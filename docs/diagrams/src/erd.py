# Builds the CropGuard ERD (Figure J.4) as SVG from the live schema export.
import csv, sys, html

SCHEMA = sys.argv[1]
OUT = sys.argv[2]

GREEN = '#11542e'
FONT = "Arial, Helvetica, sans-serif"
ROW = 21
HEAD = 32
W = 236

tables = {}
with open(SCHEMA, encoding='utf-8') as f:
    for t, col, ctype, nullable, key in csv.reader(f, delimiter='\t'):
        tables.setdefault(t, []).append((col, ctype, nullable, key))

FK = {
    ('farm_location', 'Farmer_ID'): 'users', ('farm_location', 'Type_ID'): 'crop_type',
    ('crop', 'Type_ID'): 'crop_type', ('crop', 'Farmer_ID'): 'users', ('crop', 'Location_ID'): 'farm_location',
    ('damage_report', 'Farmer_ID'): 'users', ('damage_report', 'Location_ID'): 'farm_location',
    ('damage_report', 'Crop_ID'): 'crop', ('damage_report', 'Validated_By'): 'users',
    ('damage_report', 'Approved_By'): 'users', ('damage_report', 'Technician_ID'): 'users',
    ('damage_report_images', 'Damage_Report_ID'): 'damage_report',
    ('aid_distribution', 'Damage_Report_ID'): 'damage_report', ('aid_distribution', 'Farmer_ID'): 'users',
    ('aid_distribution', 'Item_ID'): 'masterfile_items', ('aid_distribution', 'Distributed_By'): 'users',
    ('seed_requests', 'Farmer_ID'): 'users', ('notification', 'Farmer_ID'): 'users',
    ('admin_notification', 'Related_Report_ID'): 'damage_report',
    ('messages', 'Sender_ID'): 'users', ('messages', 'Receiver_ID'): 'users',
    ('system_logs', 'User_ID'): 'users',
}

# Column x positions (5 columns + 4 routing gutters)
X = {0: 30, 1: 361, 2: 692, 3: 1023, 4: 1354}
POS = {  # table: (column, top y)
    'users': (2, 30), 'damage_report': (2, 452), 'damage_report_images': (2, 905),
    'seed_requests': (1, 30), 'notification': (1, 228), 'system_logs': (1, 426), 'aid_distribution': (1, 668),
    'masterfile_items': (0, 668),
    'farm_location': (3, 30), 'crop': (3, 316), 'messages': (3, 560), 'admin_notification': (3, 760),
    'crop_type': (4, 150),
}

def box(t):
    c, y = POS[t]
    return X[c], y, W, HEAD + ROW * len(tables[t])

def row_y(t, col):
    x, y, w, h = box(t)
    for i, (c, *_rest) in enumerate(tables[t]):
        if c == col:
            return y + HEAD + ROW * i + ROW / 2
    raise KeyError(f'{t}.{col}')

def pk(t):
    return next(c for c, _t, _n, k in tables[t] if k == 'PRI')

svg = []
add = svg.append

# (child, fk, side-of-child, lane x, side-of-parent)
LINES = [
    # gutter between col1 and col2 (x 597..692)
    ('seed_requests', 'Farmer_ID', 'R', 612, 'L'),
    ('notification', 'Farmer_ID', 'R', 622, 'L'),
    ('system_logs', 'User_ID', 'R', 632, 'L'),
    ('aid_distribution', 'Farmer_ID', 'R', 642, 'L'),
    ('aid_distribution', 'Distributed_By', 'R', 652, 'L'),
    ('damage_report', 'Farmer_ID', 'L', 662, 'L'),
    ('aid_distribution', 'Damage_Report_ID', 'R', 672, 'L'),
    ('damage_report_images', 'Damage_Report_ID', 'L', 682, 'L'),
    # gutter between col2 and col3 (x 928..1023)
    ('damage_report', 'Validated_By', 'R', 940, 'R'),
    ('damage_report', 'Approved_By', 'R', 950, 'R'),
    ('damage_report', 'Technician_ID', 'R', 960, 'R'),
    ('farm_location', 'Farmer_ID', 'L', 970, 'R'),
    ('crop', 'Farmer_ID', 'L', 978, 'R'),
    ('messages', 'Sender_ID', 'L', 986, 'R'),
    ('messages', 'Receiver_ID', 'L', 994, 'R'),
    ('damage_report', 'Location_ID', 'R', 1002, 'L'),
    ('damage_report', 'Crop_ID', 'R', 1010, 'L'),
    ('admin_notification', 'Related_Report_ID', 'L', 1016, 'R'),
    # gutter between col3 and col4 (x 1259..1354)
    ('farm_location', 'Type_ID', 'R', 1290, 'L'),
    ('crop', 'Type_ID', 'R', 1300, 'L'),
    ('crop', 'Location_ID', 'R', 1280, 'R'),
    # gutter between col0 and col1 (x 266..361)
    ('aid_distribution', 'Item_ID', 'L', 312, 'R'),
]

def edge_x(t, side):
    x, y, w, h = box(t)
    return x if side == 'L' else x + w

def crow(x, y, side):
    # many (crow's foot) + zero (circle), drawn just outside the child edge
    d = -1 if side == 'L' else 1
    f = x + d * 14
    out = [f'<line x1="{f}" y1="{y}" x2="{x}" y2="{y-6}" stroke="#334155" stroke-width="1.4"/>',
           f'<line x1="{f}" y1="{y}" x2="{x}" y2="{y+6}" stroke="#334155" stroke-width="1.4"/>',
           f'<circle cx="{x + d*20}" cy="{y}" r="4" fill="white" stroke="#334155" stroke-width="1.4"/>']
    return ''.join(out)

def one(x, y, side):
    d = -1 if side == 'L' else 1
    return ''.join(f'<line x1="{x + d*k}" y1="{y-6}" x2="{x + d*k}" y2="{y+6}" stroke="#334155" stroke-width="1.6"/>' for k in (7, 11))

lines_svg, markers = [], set()
for child, col, cside, lane, pside in LINES:
    parent = FK[(child, col)]
    cx, cy = edge_x(child, cside), row_y(child, col)
    px, py = edge_x(parent, pside), row_y(parent, pk(parent))
    pts = f'{cx},{cy} {lane},{cy} {lane},{py} {px},{py}'
    lines_svg.append(f'<polyline points="{pts}" fill="none" stroke="#475569" stroke-width="1.3"/>')
    lines_svg.append(crow(cx, cy, cside))
    markers.add((px, py, pside))
for px, py, s in markers:
    lines_svg.append(one(px, py, s))

def esc(s): return html.escape(s)

boxes_svg = []
for t in POS:
    x, y, w, h = box(t)
    boxes_svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="white" stroke="{GREEN}" stroke-width="1.6"/>')
    boxes_svg.append(f'<path d="M{x},{y+6} a6,6 0 0 1 6,-6 h{w-12} a6,6 0 0 1 6,6 v{HEAD-6} h-{w} z" fill="{GREEN}"/>')
    boxes_svg.append(f'<text x="{x+w/2}" y="{y+21}" text-anchor="middle" font-family="{FONT}" font-size="15" font-weight="bold" fill="white">{esc(t)}</text>')
    for i, (c, ctype, nullable, key) in enumerate(tables[t]):
        ry = y + HEAD + ROW * i
        if i % 2 == 1:
            boxes_svg.append(f'<rect x="{x+1}" y="{ry}" width="{w-2}" height="{ROW}" fill="#f1f7f3"/>')
        tags = []
        if key == 'PRI': tags.append('PK')
        if (t, c) in FK: tags.append('FK')
        if key == 'UNI': tags.append('UK')
        tag = ','.join(tags)
        color = '#b45309' if 'PK' in tags else ('#1d4ed8' if 'FK' in tags else '#6b7280')
        weight = 'bold' if 'PK' in tags else 'normal'
        if tag:
            boxes_svg.append(f'<text x="{x+8}" y="{ry+15}" font-family="{FONT}" font-size="11" font-weight="bold" fill="{color}">{tag}</text>')
        boxes_svg.append(f'<text x="{x+48}" y="{ry+15}" font-family="{FONT}" font-size="13.5" font-weight="{weight}" fill="#111827">{esc(c)}</text>')

WIDTH, HEIGHT = 1620, 1190
legend_y = 1105
legend = [
    f'<text x="30" y="{legend_y}" font-family="{FONT}" font-size="14" font-weight="bold" fill="#111827">Legend</text>',
    f'<text x="30" y="{legend_y+24}" font-family="{FONT}" font-size="13" fill="#b45309" font-weight="bold">PK</text>',
    f'<text x="58" y="{legend_y+24}" font-family="{FONT}" font-size="13" fill="#111827">Primary key</text>',
    f'<text x="170" y="{legend_y+24}" font-family="{FONT}" font-size="13" fill="#1d4ed8" font-weight="bold">FK</text>',
    f'<text x="196" y="{legend_y+24}" font-family="{FONT}" font-size="13" fill="#111827">Foreign key</text>',
    f'<text x="306" y="{legend_y+24}" font-family="{FONT}" font-size="13" fill="#6b7280" font-weight="bold">UK</text>',
    f'<text x="334" y="{legend_y+24}" font-family="{FONT}" font-size="13" fill="#111827">Unique</text>',
    f'<line x1="420" y1="{legend_y+19}" x2="470" y2="{legend_y+19}" stroke="#475569" stroke-width="1.3"/>',
    one(470, legend_y+19, 'R').replace('x1="477"', 'x1="463"').replace('x2="477"', 'x2="463"').replace('x1="481"', 'x1="459"').replace('x2="481"', 'x2="459"'),
    f'<text x="480" y="{legend_y+24}" font-family="{FONT}" font-size="13" fill="#111827">exactly one</text>',
    f'<line x1="580" y1="{legend_y+19}" x2="630" y2="{legend_y+19}" stroke="#475569" stroke-width="1.3"/>',
    crow(630, legend_y+19, 'L').replace(f'cx="610"', 'cx="604"'),
    f'<text x="640" y="{legend_y+24}" font-family="{FONT}" font-size="13" fill="#111827">zero or many</text>',
    f'<text x="30" y="{legend_y+52}" font-family="{FONT}" font-size="12.5" fill="#4b5563">13 tables used by CropGuard (MySQL). Relationships connect each foreign key to the primary key it references.</text>',
]

with open(OUT, 'w', encoding='utf-8') as f:
    f.write(f'<svg xmlns="http://www.w3.org/2000/svg" width="{WIDTH}" height="{HEIGHT}" viewBox="0 0 {WIDTH} {HEIGHT}">')
    f.write(f'<rect width="{WIDTH}" height="{HEIGHT}" fill="white"/>')
    f.write(''.join(lines_svg))
    f.write(''.join(boxes_svg))
    f.write(''.join(legend))
    f.write('</svg>')
print('ok', OUT)
