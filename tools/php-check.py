"""PHP sanity check for this repo — there is no `php` binary in the review sandbox.

It is not a parser: it strips comments, strings, heredocs and nowdocs, then verifies that
brackets balance and flags a handful of shapes that have actually broken a deploy here
(removed/renamed helpers, `option('--flag')`, a `catch` without a variable). `php -l` is
still the real check whenever you have PHP: run it on a deploy box before you push.

    python3 tools/php-check.py app/**/*.php            # or: git diff --name-only HEAD~1
    python3 tools/php-check.py --changed               # files changed in the working tree
"""
import io
import re
import subprocess
import sys

PAIRS = {')': '(', ']': '[', '}': '{'}
OPEN = set(PAIRS.values())

SUSPECT = [
    (r"\$this->option\('--", "option() takes the bare name, not the --flag"),
    (r"\barray_except\(", "array_except() is not a Laravel helper"),
    (r"'[^']*\b[a-z]'[a-z]", "unescaped apostrophe inside a single-quoted string"),
    (r"\barray_only\(", "array_only() was removed from Laravel"),
    (r"\bstr_contains\(\s*\\\\?", None),
    (r"catch\s*\(\s*\\Throwable\s*\)\s*\{", "catch without a variable — name it"),
    (r"\)\s*->\s*;", "arrow with nothing after it"),
    (r"func_get_args\(\)", "func_get_args() inside a closure is fragile"),
]


def strip(src):
    """Blank out comments/strings/heredoc bodies, keeping newlines so line numbers hold."""
    out = []
    i, n = 0, len(src)
    state, heredoc = 'code', ''

    while i < n:
        c = src[i]
        two = src[i:i + 2]

        if state == 'code':
            if two == '//':
                state = 'line'
                i += 2
                continue
            if two == '/*':
                state = 'block'
                i += 2
                continue
            if c in ('"', "'"):
                state = c
                out.append(' ')
                i += 1
                continue
            m = re.match(r"<<<'+(\w+)'?", src[i:])
            if m:
                heredoc, state = m.group(1), 'heredoc'
                i += m.end()
                out.append('\n')
                continue
            out.append(c)
            i += 1
            continue

        if state == 'line':
            if c == '\n':
                state, _ = 'code', out.append('\n')
            i += 1
            continue

        if state == 'block':
            if two == '*/':
                state, i = 'code', i + 2
                continue
            if c == '\n':
                out.append('\n')
            i += 1
            continue

        if state in ('"', "'"):
            if c == '\\':
                i += 2
                continue
            if c == state:
                state = 'code'
            elif c == '\n':          # PHP strings do span lines; keep counting
                out.append('\n')
            i += 1
            continue

        if state == 'heredoc':
            # PHP 7.3+ allows an indented closing label, and every query in this repo is
            # indented — matching only "\nGQL" reads the rest of the file as unbalanced.
            m2 = re.search(r'\n[ \t]*' + re.escape(heredoc) + r'\b', src[i:])
            if not m2:
                return ''.join(out)
            j = i + m2.start()
            out.append('\n' * (src[i:j].count('\n') + 1))
            k = src.find(';', j)
            i = k + 1 if 0 < k < j + len(heredoc) + 40 else j + len(heredoc)
            state = 'code'
            continue

    return ''.join(out)


def check(path):
    src = io.open(path, encoding='utf8', errors='replace').read()
    clean = strip(src)
    problems, stack, line = [], [], 1

    for ch in clean:
        if ch == '\n':
            line += 1
        elif ch in OPEN:
            stack.append((ch, line))
        elif ch in PAIRS:
            if not stack:
                problems.append('%s:%d stray %s' % (path, line, ch))
            else:
                o, ol = stack.pop()
                if o != PAIRS[ch]:
                    problems.append('%s:%d %s closes %s opened on line %d' % (path, line, ch, o, ol))

    for o, ol in stack:
        problems.append('%s:%d unclosed %s' % (path, len(src.split(chr(10))), o))

    for pat, why in SUSPECT:
        if why is None:
            continue
        for m in re.finditer(pat, clean):
            problems.append('%s: %s — %s' % (path, m.group(0)[:44].strip(), why))

    return problems


def main(argv):
    if argv and argv[0] == '--changed':
        argv = [f for f in subprocess.run(
            ['git', 'diff', '--name-only', 'HEAD'], capture_output=True, text=True
        ).stdout.split('\n') if f.endswith('.php')]

    if not argv:
        print(__doc__)
        return 0

    bad = 0
    for f in argv:
        try:
            problems = check(f)
        except FileNotFoundError:
            print('%s: MISSING' % f)
            bad += 1
            continue
        if problems:
            bad += 1
            for p in problems:
                print(p)
        else:
            print('%s: ok' % f)

    if bad:
        print('\n%d file(s) need a look. On a box with php: php -l <file>' % bad)

    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
