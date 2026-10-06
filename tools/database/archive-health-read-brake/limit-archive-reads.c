#define _GNU_SOURCE
#include <dlfcn.h>
#include <errno.h>
#include <fcntl.h>
#include <inttypes.h>
#include <limits.h>
#include <pthread.h>
#include <sqlite3.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

#ifndef KIWI_BRAKE_BUILD_ID
#error "The reproducible build must supply KIWI_BRAKE_BUILD_ID"
#endif

/* Only this CLI child owns the ledger. No files, logging, or shared state. */
static ssize_t (*real_pread)(int, void *, size_t, off_t);
static ssize_t (*real_pread64)(int, void *, size_t, off64_t);
static ssize_t (*real_read)(int, void *, size_t);
static void *(*real_mmap)(void *, size_t, int, int, int, off_t);
static void *(*real_mmap64)(void *, size_t, int, int, int, off64_t);
static pthread_mutex_t ledger_lock = PTHREAD_MUTEX_INITIALIZER;
static int active, initialized, error_state, phase, target_locked;
static pid_t owner_pid;
static unsigned target_rate, rate;
static char fixture_path[PATH_MAX], target_path[PATH_MAX];
static struct stat fixture_identity, target_identity;
static char fixture_vfs[64];
static int bound_fds[128];
static size_t bound_fd_count;
static uint64_t next_slot, probe_deadline, calls, units, waits, wait_ns;
static uint64_t first_read_ns, last_read_ns, phase_started_ns;
static uint64_t probe_units, probe_elapsed_ns;

static uint64_t monotonic_ns(void)
{
    struct timespec now;
    if (clock_gettime(CLOCK_MONOTONIC, &now) != 0 || now.tv_sec < 0) {
        error_state = 1;
        return 0;
    }
    return (uint64_t)now.tv_sec * UINT64_C(1000000000) + (uint64_t)now.tv_nsec;
}

static int same_file(const struct stat *left, const struct stat *right)
{
    return S_ISREG(left->st_mode) && S_ISREG(right->st_mode)
        && left->st_dev == right->st_dev && left->st_ino == right->st_ino
        && left->st_size == right->st_size;
}

static int bind_file(const char *value, char *path, struct stat *identity)
{
    struct stat link_identity;
    return value && value[0] == '/' && strlen(value) < PATH_MAX
        && lstat(value, &link_identity) == 0 && S_ISREG(link_identity.st_mode)
        && realpath(value, path) && !strcmp(value, path)
        && stat(path, identity) == 0 && same_file(&link_identity, identity);
}

static int environment_valid(void)
{
    const char *required = getenv("KIWI_HEALTH_BRAKE_REQUIRED");
    const char *configured_rate = getenv("KIWI_HEALTH_READS_PER_SECOND");
    const char *advice = getenv("KIWI_HEALTH_RANDOM_ADVICE");
    const char *build = getenv("KIWI_HEALTH_BRAKE_BUILD_ID");
    const char *fixture = getenv("KIWI_HEALTH_BRAKE_FIXTURE");
    const char *target = getenv("KIWI_HEALTH_BRAKE_TARGET");
    return owner_pid == getpid() && required && !strcmp(required, "1")
        && configured_rate && !strcmp(configured_rate, target_rate == 700 ? "700" : "350")
        && advice && !strcmp(advice, "1") && build && !strcmp(build, KIWI_BRAKE_BUILD_ID)
        && fixture && !strcmp(fixture, fixture_path)
        && target && !strcmp(target, target_path);
}

static int health_process(void)
{
    char arguments[16384];
    int fd = open("/proc/self/cmdline", O_RDONLY | O_CLOEXEC);
    ssize_t length = fd >= 0 ? real_read(fd, arguments, sizeof(arguments)) : -1;
    if (fd >= 0) close(fd);
    if (length <= 0 || length == (ssize_t)sizeof(arguments)) return -1;
    for (ssize_t offset = 0; offset < length;) {
        size_t size = strnlen(arguments + offset, (size_t)(length - offset));
        if (size == (size_t)(length - offset)) return -1;
        if (!strcmp(arguments + offset, "--kiwi-retention-health-child")) return 1;
        offset += (ssize_t)size + 1;
    }
    return 0;
}

/* -1 is a technical fault, 0 an unrelated FD, 1 the active bound database. */
static int classify_fd(int fd)
{
    struct stat identity, current;
    char link[64], path[PATH_MAX + 1];
    int previously_bound = 0;
    for (size_t index = 0; index < bound_fd_count; index++) {
        if (bound_fds[index] == fd) previously_bound = 1;
    }
    if (!environment_valid() || error_state || fstat(fd, &identity) != 0) return -1;
    if (!S_ISREG(identity.st_mode)) return previously_bound ? -1 : 0;
    if (snprintf(link, sizeof(link), "/proc/self/fd/%d", fd) >= (int)sizeof(link)) return -1;
    ssize_t length = readlink(link, path, PATH_MAX);
    if (length <= 0 || length == PATH_MAX) return -1;
    path[length] = '\0';
    int is_fixture = identity.st_dev == fixture_identity.st_dev
        && identity.st_ino == fixture_identity.st_ino;
    int is_target = identity.st_dev == target_identity.st_dev
        && identity.st_ino == target_identity.st_ino;
    if (is_fixture || is_target) {
        const char *expected_path = is_fixture ? fixture_path : target_path;
        const struct stat *expected = is_fixture ? &fixture_identity : &target_identity;
        if (strcmp(path, expected_path) || stat(expected_path, &current) != 0
            || !same_file(expected, &identity) || !same_file(expected, &current)
            || (is_fixture && (phase != 1 && phase != 2))
            || (is_target && (phase != 3 || !target_locked))) return -1;
        if (!previously_bound) {
            if (bound_fd_count == sizeof(bound_fds) / sizeof(bound_fds[0])) return -1;
            bound_fds[bound_fd_count++] = fd;
        }
        return 1;
    }
    if (previously_bound) return -1;
    /* A replaced path, an alternate archive, or a reused fixture FD cannot escape. */
    const char *base = strrchr(path, '/');
    base = base ? base + 1 : path;
    size_t base_length = strlen(base);
    if (!strcmp(path, fixture_path) || !strcmp(path, target_path)
        || (base_length >= 7 && !strncmp(base, "kiwi_retention_archive_", 23)
            && !strcmp(base + base_length - 7, ".sqlite"))
        || !strcmp(base, strrchr(fixture_path, '/') + 1)
        || !strcmp(base, strrchr(target_path, '/') + 1)) return -1;
    return 0;
}

static int pace_section(int fd)
{
    uint64_t now = monotonic_ns();
    uint64_t deadline = next_slot > now ? next_slot : now;
    if (!now || !rate || (phase < 3 && deadline >= probe_deadline)
        || posix_fadvise(fd, 0, 0, POSIX_FADV_RANDOM) != 0) return 0;
    struct timespec absolute = {(time_t)(deadline / UINT64_C(1000000000)),
        (long)(deadline % UINT64_C(1000000000))};
    int result;
    do {
        result = clock_nanosleep(CLOCK_MONOTONIC, TIMER_ABSTIME, &absolute, NULL);
    } while (result == EINTR);
    uint64_t after = monotonic_ns();
    if (result || after < deadline || (phase < 3 && after >= probe_deadline)) return 0;
    if (deadline > now) {
        waits++;
        wait_ns += after - now;
    }
    if (!first_read_ns) first_read_ns = after;
    last_read_ns = after;
    next_slot = after + (UINT64_C(1000000000) + rate - 1) / rate;
    calls++;
    units++;
    return 1;
}

static ssize_t throttled_pread(int fd, void *buffer, size_t count, int64_t offset, int use64)
{
    int incoming_errno = errno;
    if (!active) {
        errno = incoming_errno;
        return use64 ? real_pread64(fd, buffer, count, (off64_t)offset)
            : real_pread(fd, buffer, count, (off_t)offset);
    }
    pthread_mutex_lock(&ledger_lock);
    int binding = classify_fd(fd);
    if (binding < 0) {
        error_state = 1;
        pthread_mutex_unlock(&ledger_lock);
        errno = EIO;
        return -1;
    }
    if (!binding || !count || offset < 0) {
        pthread_mutex_unlock(&ledger_lock);
        errno = incoming_errno;
        return use64 ? real_pread64(fd, buffer, count, (off64_t)offset)
            : real_pread(fd, buffer, count, (off_t)offset);
    }
    /* Match Linux's maximum single read and reject offset overflow before I/O. */
    size_t maximum = (size_t)INT_MAX & ~((size_t)sysconf(_SC_PAGESIZE) - 1);
    if (count > maximum) count = maximum;
    if ((uint64_t)offset > (uint64_t)INT64_MAX - count) {
        pthread_mutex_unlock(&ledger_lock);
        errno = EINVAL;
        return -1;
    }
    size_t done = 0;
    int outgoing_errno = incoming_errno;
    ssize_t outcome = 0;
    while (done < count) {
        size_t section = 4096 - ((uint64_t)offset + done) % 4096;
        if (section > count - done) section = count - done;
        if (classify_fd(fd) != 1 || !pace_section(fd)) {
            error_state = 1;
            outgoing_errno = EIO;
            outcome = done ? (ssize_t)done : -1;
            break;
        }
        errno = incoming_errno;
        ssize_t received = use64
            ? real_pread64(fd, (char *)buffer + done, section, (off64_t)(offset + (int64_t)done))
            : real_pread(fd, (char *)buffer + done, section, (off_t)(offset + (int64_t)done));
        outgoing_errno = errno;
        if (received < 0) {
            outcome = done ? (ssize_t)done : -1;
            break;
        }
        done += (size_t)received;
        outcome = (ssize_t)done;
        if ((size_t)received < section) break;
    }
    pthread_mutex_unlock(&ledger_lock);
    errno = outgoing_errno;
    return outcome;
}

ssize_t pread(int fd, void *buffer, size_t count, off_t offset)
{
    if (!real_pread) real_pread = dlsym(RTLD_NEXT, "pread");
    if (!real_pread) _exit(125);
    return throttled_pread(fd, buffer, count, offset, 0);
}

ssize_t pread64(int fd, void *buffer, size_t count, off64_t offset)
{
    if (!real_pread64) real_pread64 = dlsym(RTLD_NEXT, "pread64");
    if (!real_pread64) _exit(125);
    return throttled_pread(fd, buffer, count, offset, 1);
}

ssize_t read(int fd, void *buffer, size_t count)
{
    if (!real_read) real_read = dlsym(RTLD_NEXT, "read");
    if (!real_read) _exit(125);
    int saved_errno = errno;
    if (active && initialized) {
        pthread_mutex_lock(&ledger_lock);
        int binding = classify_fd(fd);
        if (binding != 0) {
            error_state = 1;
            pthread_mutex_unlock(&ledger_lock);
            errno = EIO;
            return -1;
        }
        pthread_mutex_unlock(&ledger_lock);
    }
    errno = saved_errno;
    return real_read(fd, buffer, count);
}

static int reject_mapping(int fd, int flags)
{
    if (!active || !initialized || (flags & MAP_ANONYMOUS) || fd < 0) return 0;
    pthread_mutex_lock(&ledger_lock);
    int binding = classify_fd(fd);
    if (binding != 0) error_state = 1;
    pthread_mutex_unlock(&ledger_lock);
    return binding != 0;
}

void *mmap(void *address, size_t length, int protection, int flags, int fd, off_t offset)
{
    if (!real_mmap) real_mmap = dlsym(RTLD_NEXT, "mmap");
    if (!real_mmap) _exit(125);
    int saved_errno = errno;
    if (reject_mapping(fd, flags)) { errno = EIO; return MAP_FAILED; }
    errno = saved_errno;
    return real_mmap(address, length, protection, flags, fd, offset);
}

void *mmap64(void *address, size_t length, int protection, int flags, int fd, off64_t offset)
{
    if (!real_mmap64) real_mmap64 = dlsym(RTLD_NEXT, "mmap64");
    if (!real_mmap64) _exit(125);
    int saved_errno = errno;
    if (reject_mapping(fd, flags)) { errno = EIO; return MAP_FAILED; }
    errno = saved_errno;
    return real_mmap64(address, length, protection, flags, fd, offset);
}

static int connection_identity(sqlite3 *database, char *vfs, size_t capacity)
{
    const char *name = sqlite3_db_filename(database, "main");
    if (!name || !*name) { snprintf(vfs, capacity, "memory"); return 0; }
    const char *expected_path = phase == 3 ? target_path : fixture_path;
    const struct stat *expected = phase == 3 ? &target_identity : &fixture_identity;
    struct stat identity;
    char canonical[PATH_MAX], *vfs_name = NULL;
    if (!realpath(name, canonical) || strcmp(canonical, expected_path)
        || stat(canonical, &identity) != 0 || !same_file(expected, &identity)
        || sqlite3_db_readonly(database, "main") != 1
        || sqlite3_file_control(database, "main", SQLITE_FCNTL_VFSNAME, &vfs_name) != SQLITE_OK
        || !vfs_name || (strcmp(vfs_name, "unix") && strcmp(vfs_name, "unix-excl"))) {
        sqlite3_free(vfs_name);
        return -1;
    }
    snprintf(vfs, capacity, "%s", vfs_name);
    sqlite3_free(vfs_name);
    if (phase == 1 && !*fixture_vfs) snprintf(fixture_vfs, sizeof(fixture_vfs), "%s", vfs);
    if (!*fixture_vfs || strcmp(vfs, fixture_vfs)) return -1;
    return 1;
}

static int phase_proven(uint64_t now)
{
    return calls >= 1003 && units >= calls && waits > 0 && wait_ns > 0
        && first_read_ns && last_read_ns >= first_read_ns && now >= first_read_ns
        && (now - first_read_ns) * rate >= (units - 1) * UINT64_C(980000000)
        && now < probe_deadline;
}

static void control(sqlite3_context *context, int argc, sqlite3_value **arguments)
{
    const unsigned char *action = argc == 2 ? sqlite3_value_text(arguments[0]) : NULL;
    unsigned requested = argc == 2 ? (unsigned)sqlite3_value_int(arguments[1]) : 0;
    sqlite3 *database = sqlite3_context_db_handle(context);
    const char *database_file = sqlite3_db_filename(database, "main");
    pthread_mutex_lock(&ledger_lock);
    uint64_t now = monotonic_ns();
    int permitted = action && environment_valid() && !error_state
        && (!database_file || !*database_file) && !target_locked && now;
    if (permitted && !strcmp((const char *)action, "fixture_700")) {
        permitted = phase == 0 && requested == 700;
        if (permitted) { probe_deadline = now + UINT64_C(30000000000); phase = 1; }
    } else if (permitted && !strcmp((const char *)action, "fixture_350")) {
        permitted = phase == 1 && requested == 350 && phase_proven(now);
        if (permitted) { probe_units = units; probe_elapsed_ns = now - phase_started_ns; phase = 2; }
    } else if (permitted && !strcmp((const char *)action, "target")) {
        permitted = phase == 2 && requested == target_rate && phase_proven(now)
            && units == probe_units && (now - phase_started_ns) * 100 >= probe_elapsed_ns * 135;
        if (permitted) { phase = 3; target_locked = 1; }
    } else {
        permitted = 0;
    }
    if (!permitted) {
        error_state = 1;
        sqlite3_result_error(context, "health_brake_configuration_invalid", -1);
    } else {
        rate = requested;
        calls = units = waits = wait_ns = first_read_ns = last_read_ns = next_slot = 0;
        bound_fd_count = 0;
        phase_started_ns = now;
        sqlite3_result_int(context, 1);
    }
    pthread_mutex_unlock(&ledger_lock);
}

static void status(sqlite3_context *context, int argc, sqlite3_value **arguments)
{
    (void)argc; (void)arguments;
    char buffer[1536], vfs[64] = "";
    pthread_mutex_lock(&ledger_lock);
    uint64_t now = monotonic_ns();
    int connection = connection_identity(sqlite3_context_db_handle(context), vfs, sizeof(vfs));
    if (!environment_valid() || connection < 0 || !now
        || (phase < 3 && probe_deadline && now >= probe_deadline)) error_state = 1;
    const struct stat *identity = phase == 3 ? &target_identity : &fixture_identity;
    int length = snprintf(buffer, sizeof(buffer),
        "{\"version\":1,\"build_id\":\"%s\",\"pid\":%ld,\"phase\":%d,\"rate\":%u,"
        "\"device\":\"%ju\",\"inode\":\"%ju\",\"connection_bound\":%s,\"vfs\":\"%s\","
        "\"calls\":%" PRIu64 ",\"units\":%" PRIu64 ",\"waits\":%" PRIu64 ","
        "\"wait_ns\":%" PRIu64 ",\"first_read_ns\":%" PRIu64 ",\"last_read_ns\":%" PRIu64 ","
        "\"now_ns\":%" PRIu64 ",\"phase_started_ns\":%" PRIu64 ",\"next_slot_ns\":%" PRIu64 ","
        "\"random_advice\":true,\"mapping_denied\":true,\"target_locked\":%s,\"error\":%s}",
        KIWI_BRAKE_BUILD_ID, (long)owner_pid, phase, rate,
        (uintmax_t)identity->st_dev, (uintmax_t)identity->st_ino,
        connection == 1 ? "true" : "false", vfs, calls, units, waits, wait_ns,
        first_read_ns, last_read_ns, now, phase_started_ns, next_slot,
        target_locked ? "true" : "false", error_state ? "true" : "false");
    if (length <= 0 || (size_t)length >= sizeof(buffer)) {
        error_state = 1;
        sqlite3_result_error(context, "health_brake_probe_failed", -1);
    } else {
        sqlite3_result_text(context, buffer, length, SQLITE_TRANSIENT);
    }
    pthread_mutex_unlock(&ledger_lock);
}

static int extension(sqlite3 *database, char **message, const void *api)
{
    (void)message; (void)api;
    int result = sqlite3_create_function_v2(database, "kiwi_health_brake_control_v1", 2,
        SQLITE_UTF8, NULL, control, NULL, NULL, NULL);
    if (result != SQLITE_OK) return result;
    return sqlite3_create_function_v2(database, "kiwi_health_brake_status_v1", 0,
        SQLITE_UTF8, NULL, status, NULL, NULL, NULL);
}

__attribute__((constructor)) static void initialize(void)
{
    real_read = dlsym(RTLD_NEXT, "read");
    real_pread = dlsym(RTLD_NEXT, "pread");
    real_pread64 = dlsym(RTLD_NEXT, "pread64");
    real_mmap = dlsym(RTLD_NEXT, "mmap");
    real_mmap64 = dlsym(RTLD_NEXT, "mmap64");
    if (!real_read || !real_pread || !real_pread64 || !real_mmap || !real_mmap64) _exit(125);
    int child = health_process();
    const char *required = getenv("KIWI_HEALTH_BRAKE_REQUIRED");
    if (child == 0 && !required) { initialized = 1; return; }
    if (child != 1) _exit(125);
    const char *configured_rate = getenv("KIWI_HEALTH_READS_PER_SECOND");
    if (!configured_rate || (strcmp(configured_rate, "700") && strcmp(configured_rate, "350"))) _exit(125);
    target_rate = !strcmp(configured_rate, "700") ? 700 : 350;
    owner_pid = getpid();
    if (!bind_file(getenv("KIWI_HEALTH_BRAKE_FIXTURE"), fixture_path, &fixture_identity)
        || !bind_file(getenv("KIWI_HEALTH_BRAKE_TARGET"), target_path, &target_identity)
        || (fixture_identity.st_dev == target_identity.st_dev && fixture_identity.st_ino == target_identity.st_ino)
        || !environment_valid() || !monotonic_ns()) _exit(125);
    if (sqlite3_auto_extension((void (*)(void))extension) != SQLITE_OK) _exit(125);
    active = initialized = 1;
}
