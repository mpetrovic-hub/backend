#define _GNU_SOURCE
#include <dlfcn.h>
#include <errno.h>
#include <fcntl.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

/* Loaded only by synthetic native tests, after the production library. */
static int fault(const char *name)
{
    const char *value = getenv("KIWI_TEST_FAULT");
    return value && !strcmp(value, name);
}

int posix_fadvise(int fd, off_t offset, off_t length, int advice)
{
    int (*original)(int, off_t, off_t, int) = dlsym(RTLD_NEXT, "posix_fadvise");
    return fault("fadvise") ? EIO : original(fd, offset, length, advice);
}

int clock_gettime(clockid_t clock, struct timespec *now)
{
    int (*original)(clockid_t, struct timespec *) = dlsym(RTLD_NEXT, "clock_gettime");
    if (fault("clock")) { errno = EIO; return -1; }
    return original(clock, now);
}

int clock_nanosleep(clockid_t clock, int flags, const struct timespec *deadline, struct timespec *remaining)
{
    int (*original)(clockid_t, int, const struct timespec *, struct timespec *) = dlsym(RTLD_NEXT, "clock_nanosleep");
    if (fault("sleep")) return EINVAL;
    if (fault("sleep_eintr")) { unsetenv("KIWI_TEST_FAULT"); return EINTR; }
    return original(clock, flags, deadline, remaining);
}

ssize_t readlink(const char *path, char *buffer, size_t length)
{
    ssize_t (*original)(const char *, char *, size_t) = dlsym(RTLD_NEXT, "readlink");
    if (fault("proc_fd") && !strncmp(path, "/proc/self/fd/", 14)) { errno = EACCES; return -1; }
    return original(path, buffer, length);
}

ssize_t pread(int fd, void *buffer, size_t length, off_t offset)
{
    ssize_t (*original)(int, void *, size_t, off_t) = dlsym(RTLD_NEXT, "pread");
    if (fault("pread_eintr")) { unsetenv("KIWI_TEST_FAULT"); errno = EINTR; return -1; }
    if (fault("pread_short")) { unsetenv("KIWI_TEST_FAULT"); length /= 2; }
    return original(fd, buffer, length, offset);
}

ssize_t pread64(int fd, void *buffer, size_t length, off64_t offset)
{
    ssize_t (*original)(int, void *, size_t, off64_t) = dlsym(RTLD_NEXT, "pread64");
    if (fault("pread_eintr")) { unsetenv("KIWI_TEST_FAULT"); errno = EINTR; return -1; }
    if (fault("pread_short")) { unsetenv("KIWI_TEST_FAULT"); length /= 2; }
    return original(fd, buffer, length, offset);
}
