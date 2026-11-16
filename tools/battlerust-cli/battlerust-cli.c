/*
 * battlerust-cli: runs the Rust battle engine (bin/libbattlerust.so) as a standalone process.
 *
 * Reads the battle JSON from stdin, calls fight_battle_rounds() of the library that sits next to this
 * executable (source: tools/battlerust/), and writes the result JSON to stdout. A library that exports
 * battlerust_free() gets its result string handed back for release; older ones (which never freed it) work as before. PHP starts it with proc_open()
 * (App\Game\BattleEngine\BattleEngineRust), so no PHP extension (FFI) is needed.
 *
 * Exit codes: 0 ok, 2 library not found/loadable, 3 symbol missing, 4 input error, 5 engine returned nothing.
 *
 * Build (any Linux with gcc and the same or an older glibc than the server): see build.sh.
 */
#define _GNU_SOURCE
#include <dlfcn.h>
#include <libgen.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

typedef char *(*fight_fn)(const char *input_json);
typedef void (*free_fn)(char *result);

/* the library is expected next to the executable: <dir of exe>/libbattlerust.so */
static int library_path(char *out, size_t size)
{
    char exe[PATH_MAX];
    ssize_t len = readlink("/proc/self/exe", exe, sizeof(exe) - 1);
    if (len <= 0) {
        return -1;
    }
    exe[len] = '\0';
    int written = snprintf(out, size, "%s/libbattlerust.so", dirname(exe));

    return written > 0 && (size_t) written < size ? 0 : -1;
}

/* whole stdin into a NUL-terminated buffer */
static char *read_stdin(void)
{
    size_t capacity = 1 << 16, length = 0;
    char *buffer = malloc(capacity);
    if (buffer == NULL) {
        return NULL;
    }
    size_t chunk;
    while ((chunk = fread(buffer + length, 1, capacity - length - 1, stdin)) > 0) {
        length += chunk;
        if (capacity - length <= 1) {
            capacity *= 2;
            char *grown = realloc(buffer, capacity);
            if (grown == NULL) {
                free(buffer);
                return NULL;
            }
            buffer = grown;
        }
    }
    buffer[length] = '\0';

    return buffer;
}

int main(void)
{
    char path[PATH_MAX];
    if (library_path(path, sizeof(path)) != 0) {
        fputs("battlerust-cli: cannot locate the executable\n", stderr);
        return 2;
    }

    void *library = dlopen(path, RTLD_NOW | RTLD_LOCAL);
    if (library == NULL) {
        fprintf(stderr, "battlerust-cli: %s\n", dlerror());
        return 2;
    }

    fight_fn fight = (fight_fn) dlsym(library, "fight_battle_rounds");
    if (fight == NULL) {
        fprintf(stderr, "battlerust-cli: %s\n", dlerror());
        return 3;
    }

    free_fn release = (free_fn) dlsym(library, "battlerust_free"); /* optional: absent in the first library */

    char *input = read_stdin();
    if (input == NULL || input[0] == '\0') {
        fputs("battlerust-cli: no input on stdin\n", stderr);
        return 4;
    }

    /* the library prints progress lines to stdout: send those to stderr, keep stdout for the result */
    fflush(stdout);
    int result_fd = dup(STDOUT_FILENO);
    if (result_fd < 0 || dup2(STDERR_FILENO, STDOUT_FILENO) < 0) {
        fputs("battlerust-cli: cannot redirect stdout\n", stderr);
        return 4;
    }

    char *output = fight(input);
    fflush(stdout);
    if (output == NULL) {
        fputs("battlerust-cli: engine returned no result\n", stderr);
        return 5;
    }

    FILE *result = fdopen(result_fd, "w");
    if (result == NULL) {
        return 4;
    }
    fputs(output, result);
    fclose(result);
    if (release != NULL) {
        release(output); /* the result string belongs to the library */
    }
    return 0;
}
