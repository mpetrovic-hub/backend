#define _GNU_SOURCE
#include <sqlite3.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

/* A deliberately loaded but ineffective test library. Never shipped as a brake. */
static int phase, rate, snapshots;
static long long started;
static long long monotonic_ns(void)
{
    struct timespec now;
    clock_gettime(CLOCK_MONOTONIC, &now);
    return (long long)now.tv_sec * 1000000000LL + now.tv_nsec;
}
static void control(sqlite3_context *context, int argc, sqlite3_value **arguments)
{
    (void)argc;
    phase++;
    rate = sqlite3_value_int(arguments[1]);
    snapshots = 0;
    started = monotonic_ns();
    sqlite3_result_int(context, 1);
}
static void status(sqlite3_context *context, int argc, sqlite3_value **arguments)
{
    (void)argc; (void)arguments;
    char json[1536];
    struct stat identity;
    const char *path = phase == 3 ? getenv("KIWI_HEALTH_BRAKE_TARGET") : getenv("KIWI_HEALTH_BRAKE_FIXTURE");
    stat(path, &identity);
    long long now = monotonic_ns();
    int counter = snapshots++ ? 1600 : 1;
    snprintf(json, sizeof(json),
        "{\"version\":1,\"build_id\":\"%s\",\"pid\":%ld,\"phase\":%d,\"rate\":%d,"
        "\"device\":\"%lu\",\"inode\":\"%lu\",\"connection_bound\":true,\"vfs\":\"unix\","
        "\"calls\":%d,\"units\":%d,\"waits\":%d,\"wait_ns\":%lld,"
        "\"first_read_ns\":%lld,\"last_read_ns\":%lld,\"now_ns\":%lld,"
        "\"phase_started_ns\":%lld,\"next_slot_ns\":%lld,\"random_advice\":true,"
        "\"mapping_denied\":true,\"target_locked\":false,\"error\":false}",
        getenv("KIWI_HEALTH_BRAKE_BUILD_ID"),(long)getpid(),phase,rate,
        (unsigned long)identity.st_dev,(unsigned long)identity.st_ino,
        counter,counter,counter,now-started,started,now,now,started,now);
    sqlite3_result_text(context,json,-1,SQLITE_TRANSIENT);
}
static int extension(sqlite3 *database,char **message,const void *api)
{
    (void)message; (void)api;
    sqlite3_create_function_v2(database,"kiwi_health_brake_control_v1",2,SQLITE_UTF8,NULL,control,NULL,NULL,NULL);
    return sqlite3_create_function_v2(database,"kiwi_health_brake_status_v1",0,SQLITE_UTF8,NULL,status,NULL,NULL,NULL);
}
__attribute__((constructor)) static void initialize(void)
{
#ifndef KIWI_NO_CALLBACK
    sqlite3_auto_extension((void (*)(void))extension);
#endif
}
