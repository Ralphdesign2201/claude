import sys, os, time
sys.path.insert(0, os.path.dirname(__file__))
from harness import *
from tlib import T, guard
import test_single, test_single2, test_extra, test_saas, test_catalog

def main(which):
    t = T(); insts = []
    try:
        for mode in [m for m in which if m in ('single', 'single-mysql')]:
            inst = Instance(8111 if mode == 'single' else 8113, mode); insts.append(inst); inst.start()
            if mode == 'single-mysql':
                subprocess.run(['mysql', '-uroot', '-e', 'DROP DATABASE IF EXISTS rechnung; CREATE DATABASE rechnung CHARACTER SET utf8mb4'])
                inst.mysql = 'rechnung'; test_single.MYSQL = {'host': '127.0.0.1', 'port': '3306', 'name': 'rechnung', 'user': 'rg', 'dbpass': 'pw123'}
            smtp = FakeSMTP(8125 if mode == 'single' else 8126); admin = None
            def part1():
                nonlocal admin
                admin = test_single.run(t, inst)
            guard(t, part1)
            def part2():
                nonlocal admin
                admin = test_single2.run(t, inst, admin, smtp)
            if admin: guard(t, part2)
            if admin: guard(t, test_catalog.run, t, inst, admin)
            smtp.stop()
            err = inst.errlog(); t.check('Fatal' not in err and 'Warning' not in err and 'Notice' not in err and 'Deprecated' not in err, 'PHP-Fehlerlog frei von Fatal/Warning/Notice/Deprecated:\n' + err[-1500:])
    finally:
        for i in insts: i.stop()
    if 'saas' in which:
        inst = Instance(8120, 'saas'); inst.start(); smtp = FakeSMTP(8127); prov = FakeProviders(8128)
        try: guard(t, test_saas.run, t, inst, smtp, prov)
        finally: inst.stop(); smtp.stop(); prov.stop()
    if 'dbswitch' in which: guard(t, test_extra.dbswitch, t)
    if 'upgrade' in which:
        guard(t, test_catalog.upgrade, t, 'single'); guard(t, test_catalog.upgrade, t, 'saas')
    if 'update' in which: guard(t, test_extra.updates, t)
    return t.summary()
if __name__ == '__main__': sys.exit(main(sys.argv[1:] or ['single']))
