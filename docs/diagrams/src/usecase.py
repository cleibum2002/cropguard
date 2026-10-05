# Builds the CropGuard use case diagram (Figure J.1) as SVG.
# Use cases come from what each role's pages actually do (views/<role>/*.html
# and the endpoints they call), confirmed with the team - not from what the
# server merely permits.
import sys, math, html

OUT = sys.argv[1]
GREEN = '#11542e'
INK = '#1f2937'
LINE = '#475569'
FONT = "Arial, Helvetica, sans-serif"
esc = html.escape
W, H = 1960, 1640
RX, RY = 142, 31

parts = []
add = parts.append

def text(x, y, s, size=15, anchor='middle', weight='normal', fill=INK, italic=False):
    st = ' font-style="italic"' if italic else ''
    return (f'<text x="{x}" y="{y}" font-family="{FONT}" font-size="{size}" font-weight="{weight}" '
            f'fill="{fill}" text-anchor="{anchor}"{st}>{esc(s)}</text>')

# ── Actors ──────────────────────────────────────────────────────────────────
ACTORS = {}
def actor(key, x, y, label, secondary=False):
    ACTORS[key] = (x, y)
    c = '#64748b' if secondary else INK
    g = [f'<circle cx="{x}" cy="{y-38}" r="14" fill="white" stroke="{c}" stroke-width="2.2"/>',
         f'<line x1="{x}" y1="{y-24}" x2="{x}" y2="{y+12}" stroke="{c}" stroke-width="2.2"/>',
         f'<line x1="{x-22}" y1="{y-10}" x2="{x+22}" y2="{y-10}" stroke="{c}" stroke-width="2.2"/>',
         f'<line x1="{x}" y1="{y+12}" x2="{x-16}" y2="{y+38}" stroke="{c}" stroke-width="2.2"/>',
         f'<line x1="{x}" y1="{y+12}" x2="{x+16}" y2="{y+38}" stroke="{c}" stroke-width="2.2"/>']
    for i, ln in enumerate(label.split('\n')):
        g.append(text(x, y + 60 + i * 18, ln, 15.5, 'middle', 'bold', c))
    add(''.join(g))

# ── Use cases ───────────────────────────────────────────────────────────────
UC = {}
def usecase(key, x, y, label, rx=RX):
    UC[key] = (x, y, rx)
    lines = label.split('\n')
    g = [f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{RY}" fill="#ecfdf3" stroke="{GREEN}" stroke-width="1.8"/>']
    top = y - (len(lines) - 1) * 9 + 5
    for i, ln in enumerate(lines):
        g.append(text(x, top + i * 18, ln, 14.5))
    return ''.join(g)

def edge(key, tx, ty):
    # point on the use case ellipse in the direction of (tx, ty)
    x, y, rx = UC[key]
    a = math.atan2(ty - y, tx - x)
    k = 1 / math.sqrt((math.cos(a) / rx) ** 2 + (math.sin(a) / RY) ** 2)
    return x + k * math.cos(a), y + k * math.sin(a)

lines_layer = []
def assoc(akey, ukey):
    # Ends at the oval's near side, so a line never passes behind another oval.
    ax, ay = ACTORS[akey]
    ux, uy, urx = UC[ukey]
    sx = ax + (26 if ux > ax else -26)
    sy = ay - 10
    ex, ey = (ux - urx, uy) if ux > ax else (ux + urx, uy)
    lines_layer.append(f'<line x1="{sx}" y1="{sy}" x2="{ex:.1f}" y2="{ey:.1f}" stroke="{LINE}" stroke-width="1.4"/>')

def dep(fkey, tkey, kind):
    # dashed dependency arrow from use case fkey to tkey, labelled <<include>> / <<extend>>
    fx, fy, _ = UC[fkey]; tx, ty, _ = UC[tkey]
    sx, sy = edge(fkey, tx, ty)
    ex, ey = edge(tkey, fx, fy)
    lines_layer.append(f'<line x1="{sx:.1f}" y1="{sy:.1f}" x2="{ex:.1f}" y2="{ey:.1f}" stroke="{LINE}" '
                       f'stroke-width="1.4" stroke-dasharray="7 5" marker-end="url(#open)"/>')
    mx, my = (sx + ex) / 2, (sy + ey) / 2
    lines_layer.append(f'<rect x="{mx-40}" y="{my-11}" width="80" height="18" fill="#fcfdfc"/>')
    lines_layer.append(text(mx, my + 3, f'«{kind}»', 13, 'middle', 'normal', '#334155', italic=True))

# ── Layout ──────────────────────────────────────────────────────────────────
BX0, BY0, BX1, BY1 = 300, 190, 1660, 1620   # system boundary
LX, MX, RXc = 520, 980, 1440                  # left / middle / right use-case columns

boundary = (f'<rect x="{BX0}" y="{BY0}" width="{BX1-BX0}" height="{BY1-BY0}" rx="10" fill="#fcfdfc" '
            f'stroke="{INK}" stroke-width="2"/>'
            + text(BX0 + 20, BY1 - 18, 'CropGuard: Real-Time Crop Damage Incident Management System with Analytics',
                   17, 'start', 'bold', GREEN))

ucs = []
# Shared by every signed-in user (through the User generalization)
ucs.append(usecase('profile', 560, 270, 'Manage profile\n& password'))
ucs.append(usecase('login', 870, 270, 'Log in'))
ucs.append(usecase('logout', 1150, 270, 'Log out'))
ucs.append(usecase('messages', 1430, 270, 'Exchange messages'))

# Farmer (left column)
ucs.append(usecase('register', LX, 430, 'Register account'))
ucs.append(usecase('verify', LX, 510, 'Verify email\nwith code'))
ucs.append(usecase('farms', LX, 590, 'Manage farm profiles\n(GPS location)'))
ucs.append(usecase('file', LX, 670, 'File damage report with\ngeotagged photos'))
ucs.append(usecase('offline', LX, 760, 'Save report offline\n& send automatically'))
ucs.append(usecase('editpending', LX, 845, 'Edit or delete\npending report'))
ucs.append(usecase('track', LX, 925, 'Track report status'))
ucs.append(usecase('seedreq', LX, 1005, 'Request seeds &\ntrack request'))

# Field Technician (left column, lower)
ucs.append(usecase('assigned', LX, 1180, 'View assigned\ninspections'))
ucs.append(usecase('inspect', LX, 1265, 'Submit field inspection\nwith evidence photos'))
ucs.append(usecase('updinspect', LX, 1350, 'Update inspection\nbefore approval'))
ucs.append(usecase('print', LX, 1435, 'Print inspection\nreport'))

# Middle column: notifications, and the included / extending use cases
ucs.append(usecase('notif', MX, 345, 'View notifications'))

# Right column: MAO, then shared MAO + Admin, then Admin only
STEP = 74
right = [('seedstatus', 'Update seed request\nstatus (ready / claimed)'),
         ('claim', 'Hand out aid &\nmark as claimed'),
         ('aidreports', 'View aid distribution\nreports (Excel)'),
         ('validate', 'Validate or reject\ndamage report'),
         ('map', 'View dashboard\n& damage map'),
         ('users', 'Manage user accounts\n& RSBSA numbers'),
         ('masterlists', 'Manage crop & relief\nmasterlists'),
         ('walkin', 'File walk-in report\nfor a farmer'),
         ('approve', 'Approve report\nwith relief aid'),
         ('assign', 'Assign technician &\nschedule field visit'),
         ('seedproc', 'Process seed requests'),
         ('return', 'Return report for\nre-inspection'),
         ('archive', 'Archive damage report'),
         ('sysreports', 'Generate system\nreports (Excel)'),
         ('audit', 'View audit logs')]
for i, (k, label) in enumerate(right):
    ucs.append(usecase(k, RXc, 444 + i * STEP, label))
# placed level with the use cases they belong to
ucs.append(usecase('estimate', MX, UC['approve'][1], 'Compute loss estimate\n& relief recommendation'))
ucs.append(usecase('newcrop', MX, UC['seedproc'][1], 'Register new crop'))

# Actors
actor('user', 1010, 70, 'User')
actor('email', 140, 200, 'Email Service\n(Brevo)', secondary=True)
actor('farmer', 140, 700, 'Farmer')
actor('tech', 140, 1300, 'Field\nTechnician')
actor('mao', 1820, 560, 'MAO Balingasag\nStaff')
actor('admin', 1820, 1120, 'DA Region X\nAdmin')

# Generalization: each role IS-A User (tree style, hollow triangle at User)
gen = []
gx_l, gx_r, gy = 52, 1910, 62
for k in ('farmer', 'tech'):
    x, y = ACTORS[k]
    gen.append(f'<polyline points="{x-24},{y-38} {gx_l},{y-38} {gx_l},{gy}" fill="none" stroke="{LINE}" stroke-width="1.6"/>')
for k in ('mao', 'admin'):
    x, y = ACTORS[k]
    gen.append(f'<polyline points="{x+24},{y-38} {gx_r},{y-38} {gx_r},{gy}" fill="none" stroke="{LINE}" stroke-width="1.6"/>')
ux, uy = ACTORS['user']
gen.append(f'<line x1="{gx_l}" y1="{gy}" x2="{ux-44}" y2="{gy}" stroke="{LINE}" stroke-width="1.6"/>')
gen.append(f'<line x1="{gx_r}" y1="{gy}" x2="{ux+44}" y2="{gy}" stroke="{LINE}" stroke-width="1.6"/>')
gen.append(f'<polygon points="{ux-30},{gy} {ux-44},{gy-8} {ux-44},{gy+8}" fill="white" stroke="{LINE}" stroke-width="1.6"/>')
gen.append(f'<polygon points="{ux+30},{gy} {ux+44},{gy-8} {ux+44},{gy+8}" fill="white" stroke="{LINE}" stroke-width="1.6"/>')

# Associations
for u in ('profile', 'login', 'logout', 'messages'):
    ex, ey = edge(u, ux, uy + 78)
    lines_layer.append(f'<line x1="{ux}" y1="{uy+78}" x2="{ex:.1f}" y2="{ey:.1f}" stroke="{LINE}" stroke-width="1.4"/>')
for u in ('register', 'farms', 'file', 'editpending', 'track', 'seedreq'):
    assoc('farmer', u)
for u in ('assigned', 'inspect', 'updinspect', 'print'):
    assoc('tech', u)
for u in ('seedstatus', 'claim', 'aidreports', 'validate', 'map'):
    assoc('mao', u)
for u in ('validate', 'map', 'users', 'masterlists', 'walkin', 'approve', 'assign',
          'seedproc', 'return', 'archive', 'sysreports', 'audit'):
    assoc('admin', u)
assoc('email', 'verify')
assoc('email', 'profile')

# View notifications: Farmer, MAO and Admin (technicians have no notifications).
# Routed around the edges so these lines never cross other use cases.
nx, ny, nrx = UC['notif']
fx, fy = ACTORS['farmer']
lines_layer.append(f'<polyline points="{fx},{fy-52} {fx},{ny} {nx-nrx},{ny}" fill="none" stroke="{LINE}" stroke-width="1.4"/>')
for k in ('mao', 'admin'):
    ax, ay = ACTORS[k]
    lines_layer.append(f'<polyline points="{ax+24},{ay-4} {ax+58},{ay-4} {ax+58},{ny} {nx+nrx},{ny}" fill="none" stroke="{LINE}" stroke-width="1.4"/>')

# include / extend
dep('register', 'verify', 'include')
dep('offline', 'file', 'extend')
dep('approve', 'estimate', 'include')
dep('newcrop', 'seedproc', 'extend')

with open(OUT, 'w', encoding='utf-8') as f:
    f.write(f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">')
    f.write('<defs><marker id="open" viewBox="0 0 12 12" refX="11" refY="6" markerWidth="11" markerHeight="11" '
            f'orient="auto"><path d="M1,1 L11,6 L1,11" fill="none" stroke="{LINE}" stroke-width="1.6"/></marker></defs>')
    f.write(f'<rect width="{W}" height="{H}" fill="white"/>')
    f.write(boundary)
    f.write(''.join(gen))
    f.write(''.join(lines_layer))
    f.write(''.join(ucs))
    f.write(''.join(parts))
    f.write('</svg>')
print('ok')
