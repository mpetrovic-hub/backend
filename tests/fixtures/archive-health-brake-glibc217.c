#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <gnu/libc-version.h>
#include <sqlite3.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

static void require(int valid, const char *message)
{
    if (!valid) {
        if (write(STDERR_FILENO, "FAIL ", 5) < 0
            || write(STDERR_FILENO, message, strlen(message)) < 0
            || write(STDERR_FILENO, "\n", 1) < 0) _exit(2);
        exit(2);
    }
}

static double seconds(void)
{
    struct timespec now;
    require(clock_gettime(CLOCK_MONOTONIC, &now) == 0, "clock");
    return (double)now.tv_sec + (double)now.tv_nsec / 1000000000.0;
}

static void execute(sqlite3 *db, const char *sql)
{
    require(sqlite3_exec(db, sql, NULL, NULL, NULL) == SQLITE_OK, sql);
}

static sqlite3 *open_readonly(const char *path)
{
    sqlite3 *db = NULL;
    int result = sqlite3_open_v2(path, &db, SQLITE_OPEN_READONLY, NULL);
    if (result != SQLITE_OK) {
        char detail[100];
        snprintf(detail, sizeof(detail), "readonly open extended_code=%d", sqlite3_extended_errcode(db));
        require(0, detail);
    }
    execute(db, "PRAGMA query_only=ON; PRAGMA mmap_size=0");
    return db;
}

static void integrity(sqlite3 *db)
{
    sqlite3_stmt *statement = NULL;
    require(sqlite3_prepare_v2(db, "PRAGMA integrity_check", -1, &statement, NULL) == SQLITE_OK, "prepare integrity");
    require(sqlite3_step(statement) == SQLITE_ROW && !strcmp((const char *)sqlite3_column_text(statement, 0), "ok"), "full integrity result");
    require(sqlite3_step(statement) == SQLITE_DONE, "complete integrity");
    sqlite3_finalize(statement);
}

static void verify_status(sqlite3 *db)
{
    sqlite3_stmt *statement = NULL;
    require(sqlite3_prepare_v2(db, "SELECT kiwi_health_brake_status_v1()", -1, &statement, NULL) == SQLITE_OK, "status callback");
    require(sqlite3_step(statement) == SQLITE_ROW, "status row");
    const char *status = (const char *)sqlite3_column_text(statement, 0);
    require(status && strstr(status, "\"error\":false") && strstr(status, "\"connection_bound\":true"), "bound error-free status");
    sqlite3_finalize(statement);
}

int main(void)
{
    require(!strcmp(gnu_get_libc_version(), "2.17"), "actual glibc 2.17");
    sqlite3 *control = NULL;
    require(sqlite3_open(":memory:", &control) == SQLITE_OK, "control open");
    double durations[2];
    const char *phases[] = {"SELECT kiwi_health_brake_control_v1('fixture_700',700)", "SELECT kiwi_health_brake_control_v1('fixture_350',350)"};
    for (int index = 0; index < 2; index++) {
        execute(control, phases[index]);
        sqlite3 *fixture = open_readonly(getenv("KIWI_HEALTH_BRAKE_FIXTURE"));
        double started = seconds();
        integrity(fixture);
        verify_status(fixture);
        durations[index] = seconds() - started;
        sqlite3_close(fixture);
    }
    require(durations[1] >= durations[0] * 1.35, "two rates");
    execute(control, "SELECT kiwi_health_brake_control_v1('target',700)");
    int fd = open(getenv("KIWI_HEALTH_BRAKE_TARGET"), O_RDONLY);
    require(fd >= 0, "target fd");
    char *bytes = malloc(1024 * 1024);
    require(bytes != NULL, "buffer");
    double started = seconds();
    require(pread(fd, bytes, 1024 * 1024, 0) == 1024 * 1024, "paced pread");
    require(seconds() - started >= .98 * 255 / 700, "pread duration");
    require(!memcmp(bytes, "SQLite format 3", 15), "preserved header");
    started = seconds();
    require(pread64(fd, bytes, 8192, 4094) == 8192, "unaligned pread64");
    require(seconds() - started >= .98 * 2 / 700, "unaligned duration");
    errno = 0;
    require(pread64(fd, bytes, 1, -1) == -1 && errno == EINVAL, "negative offset");
    close(fd);
    free(bytes);
    sqlite3_close(control);
    printf("{\"result\":\"PASS\",\"glibc\":\"%s\",\"sqlite\":\"%s\",\"fixture_700_seconds\":%.6f,\"fixture_350_seconds\":%.6f,\"pread\":true,\"pread64\":true}\n", gnu_get_libc_version(), sqlite3_libversion(), durations[0], durations[1]);
    return 0;
}
