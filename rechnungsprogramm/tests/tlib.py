import re, sys, traceback
class T:
    def __init__(self): self.ok = 0; self.fail = []; self.section = ''
    def sec(self, name): self.section = name; print(f'\n== {name}')
    def check(self, cond, msg):
        if cond: self.ok += 1
        else: self.fail.append(f'[{self.section}] {msg}'); print('  FAIL:', msg)
    def eq(self, a, b, msg): self.check(a == b, f'{msg} (erwartet {b!r}, erhalten {a!r})')
    def summary(self):
        print(f'\n{self.ok} Prüfungen bestanden, {len(self.fail)} fehlgeschlagen')
        for f in self.fail: print(' -', f)
        return 0 if not self.fail else 1
def guard(t, fn, *a):
    try: fn(*a)
    except Exception as e:
        t.fail.append(f'[{t.section}] Abbruch durch Ausnahme: {e!r}'); print('  EXCEPTION:', e); traceback.print_exc()
