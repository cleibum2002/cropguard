# Builds the CropGuard DFDs (Figure J.2 context diagram, Figure J.3 Level 1 in
# two parts) as SVG. Gane-Sarson notation.
import sys, html

OUTDIR = sys.argv[1]
GREEN = '#11542e'
INK = '#1f2937'
LINE = '#374151'
FONT = "Arial, Helvetica, sans-serif"
esc = html.escape

DEFS = (f'<defs><marker id="ah" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="9" markerHeight="9" orient="auto-start-reverse">'
        f'<path d="M0,0 L10,5 L0,10 z" fill="{LINE}"/></marker></defs>')

def text(x, y, s, size=16, anchor='start', weight='normal', fill=INK):
    return (f'<text x="{x}" y="{y}" font-family="{FONT}" font-size="{size}" font-weight="{weight}" '
            f'fill="{fill}" text-anchor="{anchor}">{esc(s)}</text>')

def arrow(x1, y1, x2, y2, both=False):
    start = ' marker-start="url(#ah)"' if both else ''
    return (f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{LINE}" stroke-width="1.6" '
            f'marker-end="url(#ah)"{start}/>')

def external(x, y, w, h, name, dup=False, size=17):
    lines = name.split('\n')
    out = [f'<rect x="{x+4}" y="{y+4}" width="{w}" height="{h}" fill="#cbd5e1"/>',
           f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="white" stroke="{INK}" stroke-width="1.8"/>']
    if dup:  # Gane-Sarson duplicate-entity mark
        out.append(f'<line x1="{x+w-16}" y1="{y+h}" x2="{x+w}" y2="{y+h-16}" stroke="{INK}" stroke-width="1.6"/>')
    top = y + h / 2 - (len(lines) - 1) * (size + 3) / 2 + size * 0.35
    for i, ln in enumerate(lines):
        out.append(text(x + w / 2, top + i * (size + 3), ln, size, 'middle', 'bold'))
    return ''.join(out)

def process(x, y, w, h, pid, name_lines, size=16):
    out = [f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="14" fill="#ecfdf3" stroke="{GREEN}" stroke-width="2"/>',
           f'<line x1="{x}" y1="{y+30}" x2="{x+w}" y2="{y+30}" stroke="{GREEN}" stroke-width="1.5"/>',
           text(x + w / 2, y + 21, pid, 15, 'middle', 'bold', GREEN)]
    body_top = y + 30
    body_h = h - 30
    top = body_top + body_h / 2 - (len(name_lines) - 1) * (size + 4) / 2 + size * 0.35
    for i, ln in enumerate(name_lines):
        out.append(text(x + w / 2, top + i * (size + 4), ln, size, 'middle', 'bold'))
    return ''.join(out)

def store(x, y, w, h, sid, name, dup=False):
    out = [f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="#f8fafc" stroke="none"/>',
           f'<line x1="{x}" y1="{y}" x2="{x+w}" y2="{y}" stroke="{INK}" stroke-width="1.6"/>',
           f'<line x1="{x}" y1="{y+h}" x2="{x+w}" y2="{y+h}" stroke="{INK}" stroke-width="1.6"/>',
           f'<line x1="{x}" y1="{y}" x2="{x}" y2="{y+h}" stroke="{INK}" stroke-width="1.6"/>',
           f'<line x1="{x+46}" y1="{y}" x2="{x+46}" y2="{y+h}" stroke="{INK}" stroke-width="1.4"/>']
    if dup:  # Gane-Sarson duplicate-store mark
        out.append(f'<line x1="{x+7}" y1="{y}" x2="{x+7}" y2="{y+h}" stroke="{INK}" stroke-width="1.4"/>')
    out.append(text(x + 27, y + h / 2 + 5, sid, 14, 'middle', 'bold'))
    out.append(text(x + 56, y + h / 2 + 5, name, 14.5))
    return ''.join(out)

def svg_doc(w, h, body):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}">'
            f'{DEFS}<rect width="{w}" height="{h}" fill="white"/>{body}</svg>')

# ── Level 0 (context diagram) ───────────────────────────────────────────────
def level0():
    b = []
    PX, PY, PW, PH = 470, 330, 360, 230
    b.append(process(PX, PY, PW, PH, '0', ['CropGuard', 'Real-Time Crop Damage', 'Incident Management', 'System with Analytics'], 17))
    b.append(external(30, 410, 220, 70, 'Farmer'))
    b.append(external(1050, 410, 220, 70, 'Field Technician'))
    b.append(external(540, 30, 220, 70, 'MAO Balingasag\nStaff'))
    b.append(external(540, 790, 220, 70, 'DA Region X\nAdmin'))
    b.append(external(1050, 650, 220, 70, 'Email Service\n(Brevo)'))

    def stack(x, y_last, lines, anchor, up=True):
        out = []
        n = len(lines)
        for i, s in enumerate(lines):
            yy = y_last - (n - 1 - i) * 19 if up else y_last + i * 19
            out.append(text(x, yy, s, 15.5, anchor))
        return ''.join(out)

    # Farmer
    b.append(arrow(250, 430, PX, 430))
    b.append(stack(360, 418, ['Registration & login', 'Farm details + GPS', 'Damage reports + photos', 'Seed requests, messages'], 'middle'))
    b.append(arrow(PX, 462, 250, 462))
    b.append(stack(360, 486, ['Login & verification result', 'Report & aid notices', 'Seed request status', 'Messages from DA staff'], 'middle', up=False))
    # Field Technician
    b.append(arrow(1050, 430, PX + PW, 430))
    b.append(stack(940, 418, ['Inspection findings', 'Confirmed severity', 'Geotagged evidence photos', 'Messages'], 'middle'))
    b.append(arrow(PX + PW, 462, 1050, 462))
    b.append(stack(940, 486, ['Assigned inspections', 'Visit dates & farm details', 'Messages'], 'middle', up=False))
    # MAO
    b.append(arrow(620, 100, 620, PY))
    b.append(stack(606, 140, ['Validate / reject reports', 'Aid claimed confirmations', 'Seed request updates', 'Messages'], 'end', up=False))
    b.append(arrow(680, PY, 680, 100))
    b.append(stack(694, 140, ['New-report alerts', 'Validation queue', 'Aid awaiting pickup', 'Distribution reports, map', 'Messages'], 'start', up=False))
    # Admin
    b.append(arrow(620, 790, 620, PY + PH))
    b.append(stack(606, 606, ['User accounts, RSBSA', 'Crop & relief masterlists', 'Walk-in reports, decisions', 'Assignments, approvals', 'Seed decisions, messages'], 'end', up=False))
    b.append(arrow(680, PY + PH, 680, 790))
    b.append(stack(694, 606, ['Loss estimates & relief', 'Analytics & damage map', 'System reports / Excel', 'Audit logs & alerts', 'Messages'], 'start', up=False))
    # Email service
    b.append(arrow(PX + PW, 545, 1050, 685))
    b.append(text(948, 592, 'Verification code emails', 15.5, 'start'))
    return svg_doc(1300, 890, ''.join(b))

# ── Level 1 (row layout, two parts) ─────────────────────────────────────────
FARMER, TECH, MAO, ADMIN, EMAIL = 'Farmer', 'Field Technician', 'MAO Balingasag\nStaff', 'DA Region X\nAdmin', 'Email Service\n(Brevo)'
S = {'D1': 'Users', 'D2': 'Farms & Crops', 'D3': 'Crop Masterlist', 'D4': 'Damage Reports', 'D5': 'Report Photos',
     'D6': 'Relief Inventory', 'D7': 'Aid Ledger', 'D8': 'Notifications', 'D9': 'Audit Logs', 'D10': 'Seed Requests',
     'D11': 'Messages'}

# ext flow: ('in', label) = entity -> process, ('out', label) = process -> entity, ('both', label)
ROWS = [
    ('1.0', ['Authenticate &', 'Manage Accounts'],
     [(FARMER, [('in', 'Registration & login details'), ('out', 'Login & verification result')]),
      (ADMIN, [('in', 'User accounts, roles & RSBSA'), ('out', 'User list')]),
      (EMAIL, [('out', 'Verification code email')])],
     [('D1', 'rw', 'Account records'), ('D9', 'w', 'Login & account logs')]),
    ('2.0', ['Maintain Crop &', 'Relief Masterlists'],
     [(ADMIN, [('in', 'Crops, costs, relief items & stock'), ('out', 'Masterlist records')])],
     [('D3', 'rw', 'Crop types & cost/ha'), ('D6', 'rw', 'Items & stock'), ('D9', 'w', 'Change logs')]),
    ('3.0', ['Manage Farm', 'Profiles'],
     [(FARMER, [('in', 'Farm details + GPS location'), ('out', 'Registered farms')])],
     [('D2', 'rw', 'Farm & crop records'), ('D3', 'r', 'Crop types')]),
    ('4.0', ['Submit Damage', 'Report (online or', 'queued offline)'],
     [(FARMER, [('in', 'Damage report + geotagged photos'), ('out', 'Submission confirmation')]),
      (ADMIN, [('in', 'Walk-in report for a farmer')])],
     [('D1', 'r', 'RSBSA number'), ('D2', 'rw', 'Farm & crop details'), ('D4', 'w', 'New damage report'),
      ('D5', 'w', 'Photos + GPS'), ('D8', 'w', 'New-report alerts')]),
    ('5.0', ['Validate Report &', 'Assign Inspection'],
     [(MAO, [('in', 'Validate / reject report'), ('out', 'Validation queue')]),
      (ADMIN, [('in', 'Validate, reject, archive, assign')])],
     [('D1', 'r', 'Active technicians'), ('D4', 'rw', 'Status & assignment'), ('D8', 'w', 'Status notices'),
      ('D9', 'w', 'Decision logs')]),
    ('6.0', ['Conduct Field', 'Inspection'],
     [(TECH, [('in', 'Findings, severity, evidence photos'), ('out', 'Assigned inspections & visit dates')]),
      (ADMIN, [('in', 'Return for re-inspection')])],
     [('D4', 'rw', 'Inspection result'), ('D5', 'w', 'Evidence photos + GPS'), ('D8', 'w', 'Inspection notices'),
      ('D9', 'w', 'Inspection logs')]),
    ('7.0', ['Estimate Loss &', 'Distribute Aid'],
     [(ADMIN, [('in', 'Approval with relief item & qty'), ('out', 'Loss estimate & relief recommendation')]),
      (MAO, [('in', 'Aid claimed confirmation'), ('out', 'Aid awaiting pickup')])],
     [('D3', 'r', 'Production cost/ha'), ('D4', 'rw', 'Approved status'), ('D6', 'rw', 'Stock deduction'),
      ('D7', 'rw', 'Aid records'), ('D8', 'w', 'Approval & claim notices'), ('D9', 'w', 'Approval & claim logs')]),
    ('8.0', ['Process Seed', 'Requests'],
     [(FARMER, [('in', 'Seed request'), ('out', 'Request status')]),
      (ADMIN, [('in', 'Approve / reject (new crop details)')]),
      (MAO, [('in', 'Ready for pickup / claimed')])],
     [('D10', 'rw', 'Request records'), ('D3', 'w', 'Newly registered crop'), ('D8', 'w', 'Request notices')]),
    ('9.0', ['Exchange', 'Messages'],
     [(FARMER, [('both', 'Messages')]), (TECH, [('both', 'Messages')]), (MAO, [('both', 'Messages')]),
      (ADMIN, [('both', 'Messages')])],
     [('D11', 'rw', 'Message threads')]),
    ('10.0', ['Deliver', 'Notifications'],
     [(FARMER, [('out', 'Report, aid & seed notices')]), (MAO, [('out', 'New-report & aid alerts')]),
      (ADMIN, [('out', 'New-report & inspection alerts')])],
     [('D8', 'rw', 'Unread / mark as read')]),
    ('11.0', ['Generate Reports', '& Analytics'],
     [(ADMIN, [('in', 'Filters & date range'), ('out', 'Reports, Excel, analytics, audit logs')]),
      (MAO, [('out', 'Distribution reports & damage map')])],
     [('D2', 'r', 'Farm locations'), ('D4', 'r', 'Report data'), ('D7', 'r', 'Aid records'), ('D9', 'r', 'Audit entries')]),
]

# Count appearances across BOTH parts, for duplicate marks.
ext_count, store_count = {}, {}
for _p, _n, exts, stores in ROWS:
    for e, _f in exts: ext_count[e] = ext_count.get(e, 0) + 1
    for s, _d, _l in stores: store_count[s] = store_count.get(s, 0) + 1

EX, EW = 20, 190          # external entity column
PXc, PWc = 540, 250       # process column
SX, SW = 960, 230         # data store column
EXT_SLOT, ST_SLOT = 72, 50
WIDTH = SX + SW + 20

def level1(rows, title, legend):
    b, y = [], 70
    b.append(text(EX + EW / 2, 40, 'External Entity', 15, 'middle', 'bold', '#6b7280'))
    b.append(text(PXc + PWc / 2, 40, 'Process', 15, 'middle', 'bold', '#6b7280'))
    b.append(text(SX + SW / 2, 40, 'Data Store', 15, 'middle', 'bold', '#6b7280'))
    b.append(f'<line x1="10" y1="54" x2="{WIDTH-10}" y2="54" stroke="#d1d5db" stroke-width="1.2"/>')
    for idx, (pid, name, exts, stores) in enumerate(rows):
        n_ext_lines = sum(1 for _ in exts)
        row_h = max(len(exts) * EXT_SLOT, len(stores) * ST_SLOT, 120) + 24
        if idx % 2 == 0:
            b.append(f'<rect x="10" y="{y}" width="{WIDTH-20}" height="{row_h}" fill="#f6faf7"/>')
        # process box
        ph = row_h - 26
        b.append(process(PXc, y + 13, PWc, ph, pid, name))
        # externals
        ext_block = len(exts) * EXT_SLOT
        ey0 = y + (row_h - ext_block) / 2
        for i, (ename, flows) in enumerate(exts):
            ey = ey0 + i * EXT_SLOT + (EXT_SLOT - 50) / 2
            b.append(external(EX, ey, EW, 50, ename, dup=ext_count[ename] > 1, size=15.5))
            cy = ey + 25
            if len(flows) == 2:
                (d1, l1), (d2, l2) = flows
                ya, yb = cy - 8, cy + 8
                b.append(arrow(EX + EW, ya, PXc, ya) if d1 == 'in' else arrow(PXc, ya, EX + EW, ya))
                b.append(text((EX + EW + PXc) / 2, ya - 6, l1, 14.5, 'middle'))
                b.append(arrow(EX + EW, yb, PXc, yb) if d2 == 'in' else arrow(PXc, yb, EX + EW, yb))
                b.append(text((EX + EW + PXc) / 2, yb + 18, l2, 14.5, 'middle'))
            else:
                d, l = flows[0]
                if d == 'in':
                    b.append(arrow(EX + EW, cy, PXc, cy))
                elif d == 'out':
                    b.append(arrow(PXc, cy, EX + EW, cy))
                else:
                    b.append(arrow(EX + EW, cy, PXc, cy, both=True))
                b.append(text((EX + EW + PXc) / 2, cy - 7, l, 14.5, 'middle'))
        # stores
        st_block = len(stores) * ST_SLOT
        sy0 = y + (row_h - st_block) / 2
        for i, (sid, d, l) in enumerate(stores):
            sy = sy0 + i * ST_SLOT + (ST_SLOT - 34) / 2
            b.append(store(SX, sy, SW, 34, sid, S[sid], dup=store_count[sid] > 1))
            cy = sy + 17
            x1, x2 = PXc + PWc, SX
            if d == 'w':
                b.append(arrow(x1, cy, x2, cy))
            elif d == 'r':
                b.append(arrow(x2, cy, x1, cy))
            else:
                b.append(arrow(x1, cy, x2, cy, both=True))
            b.append(text((x1 + x2) / 2, cy - 6, l, 13.5, 'middle'))
        y += row_h
    if legend:
        y += 22
        b.append(text(EX, y, 'Legend (Gane-Sarson notation)', 15, 'start', 'bold'))
        y += 16
        b.append(external(EX, y, 130, 40, 'Entity', size=14))
        b.append(text(EX + 140, y + 25, 'External entity', 14))
        b.append(external(EX + 290, y, 130, 40, 'Entity', dup=True, size=14))
        b.append(text(EX + 430, y + 25, 'Same entity shown again', 14))
        b.append(store(EX + 620, y + 3, 150, 34, 'D#', 'Store'))
        b.append(text(EX + 780, y + 25, 'Data store', 14))
        b.append(store(EX + 880, y + 3, 150, 34, 'D#', 'Store', dup=True))
        b.append(text(EX + 1040, y + 25, 'Shown again', 14))
        y += 52
        b.append(arrow(EX, y, EX + 60, y))
        b.append(text(EX + 70, y + 5, 'Data flow (arrow = direction)', 14))
        b.append(arrow(EX + 330, y, EX + 390, y, both=True))
        b.append(text(EX + 400, y + 5, 'Read and write', 14))
        y += 20
    return svg_doc(WIDTH, y + 20, ''.join(b))

with open(f'{OUTDIR}/dfd_level0.svg', 'w', encoding='utf-8') as f:
    f.write(level0())
with open(f'{OUTDIR}/dfd_level1_part1.svg', 'w', encoding='utf-8') as f:
    f.write(level1(ROWS[:6], 'Part 1', legend=True))
with open(f'{OUTDIR}/dfd_level1_part2.svg', 'w', encoding='utf-8') as f:
    f.write(level1(ROWS[6:], 'Part 2', legend=True))
print('ok')
