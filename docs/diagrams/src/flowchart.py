# Builds the CropGuard damage-report process flowchart (swimlanes: Farmer,
# MAO, Admin, Technician) as SVG, in the same plain style as the DFDs:
# white background, Arial, dark ink, standard flowchart symbols
# (terminator, process, decision).
#   python docs/diagrams/src/flowchart.py docs/diagrams
import sys, html

OUTDIR = sys.argv[1]
INK = '#1f2937'
LINE = '#374151'
MUTED = '#4b5563'
GREEN = '#11542e'
RED = '#b42318'
FONT = "Arial, Helvetica, sans-serif"
S = 1.75                      # layout units -> px
esc = html.escape
p = lambda v: round(v * S, 1)

DEFS = (f'<defs><marker id="ah" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="9" markerHeight="9" orient="auto-start-reverse">'
        f'<path d="M0,0 L10,5 L0,10 z" fill="{LINE}"/></marker>'
        f'<marker id="ahr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="9" markerHeight="9" orient="auto-start-reverse">'
        f'<path d="M0,0 L10,5 L0,10 z" fill="{RED}"/></marker></defs>')

def text(x, y, s, size=16, anchor='middle', weight='normal', fill=INK, italic=False):
    st = ' font-style="italic"' if italic else ''
    return (f'<text x="{p(x)}" y="{p(y)}" font-family="{FONT}" font-size="{size}" font-weight="{weight}" '
            f'fill="{fill}" text-anchor="{anchor}"{st}>{esc(s)}</text>')

def shadow_rect(x, y, w, h, rx=0, dash=False, stroke=INK):
    d = ' stroke-dasharray="8 5"' if dash else ''
    return (f'<rect x="{p(x)+4}" y="{p(y)+4}" width="{p(w)}" height="{p(h)}" rx="{rx}" fill="#cbd5e1"/>'
            f'<rect x="{p(x)}" y="{p(y)}" width="{p(w)}" height="{p(h)}" rx="{rx}" fill="white" stroke="{stroke}" stroke-width="1.8"{d}/>')

def step(cx, y, h, title, sub, tag=None, reject=False):
    x, w = cx - 100, 200
    out = [shadow_rect(x, y, w, h, 8, dash=reject, stroke=RED if reject else INK),
           text(cx, y + h / 2 - 3, title, 19, weight='bold'),
           text(cx, y + h / 2 + 15, sub, 15, fill=MUTED)]
    if tag:
        out.append(text(cx, y + h + 17, tag, 14, weight='bold', fill=GREEN, italic=True))
    return ''.join(out)

def terminal(cx, cy, label):
    return (f'<rect x="{p(cx-50)+4}" y="{p(cy-20)+4}" width="{p(100)}" height="{p(40)}" rx="{p(20)}" fill="#cbd5e1"/>'
            f'<rect x="{p(cx-50)}" y="{p(cy-20)}" width="{p(100)}" height="{p(40)}" rx="{p(20)}" fill="white" stroke="{INK}" stroke-width="1.8"/>'
            + text(cx, cy + 4, label, 18, weight='bold'))

def decision(cx, cy, lines):
    pts = f'{p(cx)},{p(cy-45)} {p(cx+75)},{p(cy)} {p(cx)},{p(cy+45)} {p(cx-75)},{p(cy)}'
    sh = f'{p(cx)+4},{p(cy-45)+4} {p(cx+75)+4},{p(cy)+4} {p(cx)+4},{p(cy+45)+4} {p(cx-75)+4},{p(cy)+4}'
    out = [f'<polygon points="{sh}" fill="#cbd5e1"/>', f'<polygon points="{pts}" fill="white" stroke="{INK}" stroke-width="1.8"/>']
    top = cy - (len(lines) - 1) * 5 + 3
    for i, ln in enumerate(lines):
        out.append(text(cx, top + i * 11, ln, 18, weight='bold'))
    return ''.join(out)

def flow(points, red=False, dash=False):
    d = 'M' + ' L'.join(f'{p(x)},{p(y)}' for x, y in points)
    col, mk = (RED, 'ahr') if red else (LINE, 'ah')
    da = ' stroke-dasharray="9 6"' if dash else ''
    return f'<path d="{d}" fill="none" stroke="{col}" stroke-width="1.8"{da} marker-end="url(#{mk})"/>'

def label(x, y, s, red=False, anchor='start'):
    return text(x, y, s, 15, anchor=anchor, weight='bold', fill=RED if red else INK)

W, H = 1070, 1000
LANES = [('FARMER', 160), ('MAO', 410), ('ADMIN (DA REGION X)', 660), ('TECHNICIAN', 910)]

def build():
    b = []
    # swimlanes: header boxes and divider lines (draw.io style)
    b.append(f'<rect x="{p(35)}" y="{p(14)}" width="{p(1000)}" height="{p(972)}" fill="none" stroke="{INK}" stroke-width="1.8"/>')
    for i, (name, cx) in enumerate(LANES):
        x = 35 + i * 250
        b.append(f'<rect x="{p(x)}" y="{p(14)}" width="{p(250)}" height="{p(42)}" fill="#f3f4f6" stroke="{INK}" stroke-width="1.8"/>')
        b.append(text(cx, 41, name, 19, weight='bold'))
        if i:
            b.append(f'<line x1="{p(x)}" y1="{p(56)}" x2="{p(x)}" y2="{p(986)}" stroke="{INK}" stroke-width="1.2"/>')

    b.append(terminal(160, 100, 'Start'))
    b.append(flow([(160, 120), (160, 148)]))
    b.append(step(160, 150, 60, 'File damage report', 'photos, GPS, area, severity', 'Status: Pending'))
    b.append(flow([(260, 180), (308, 180)]))
    b.append(step(410, 150, 60, 'Review the report', 'checks photos and details'))
    b.append(flow([(410, 210), (410, 243)]))
    b.append(decision(410, 290, ['Real and', 'complete?']))
    b.append(flow([(335, 290), (262, 290)], red=True))
    b.append(label(298, 282, 'No', red=True, anchor='middle'))
    b.append(flow([(410, 335), (410, 368)]))
    b.append(label(418, 356, 'Yes'))
    b.append(step(160, 260, 60, 'Report rejected', "farmer sees the MAO's reason", reject=True))
    b.append(flow([(160, 320), (160, 346)]))
    b.append(terminal(160, 368, 'End'))

    b.append(step(410, 370, 56, 'Approve the report', 'report is confirmed', 'Status: Approved; farmer notified'))
    b.append(flow([(510, 398), (558, 398)]))
    b.append(step(660, 370, 56, 'Assign technician', 'and visit date (max 3 a day)', 'Farmer told: visit within the week'))
    b.append(flow([(760, 398), (910, 398), (910, 478)]))
    b.append(step(910, 480, 60, 'Inspect the farm', 'damaged area, severity, photos', 'Status: Inspected; farmer notified'))
    b.append(flow([(810, 510), (762, 510)]))
    b.append(step(660, 480, 60, 'Review the inspection', 'findings and photos'))
    b.append(flow([(660, 540), (660, 593)]))
    b.append(decision(660, 640, ['Inspection', 'decision']))
    # return to technician
    b.append(flow([(735, 640), (1022, 640), (1022, 510), (1012, 510)], dash=True))
    b.append(label(795, 632, 'Incomplete: return'))
    # reject
    b.append(flow([(585, 640), (262, 640)], red=True))
    b.append(label(300, 632, 'Not eligible: reject', red=True))
    b.append(step(160, 610, 60, 'Report rejected', "farmer sees the Admin's reason", reject=True))
    b.append(flow([(160, 670), (160, 696)]))
    b.append(terminal(160, 718, 'End'))
    # approve
    b.append(flow([(660, 685), (660, 728)]))
    b.append(label(668, 712, 'Approve'))
    b.append(step(660, 730, 60, 'Allocate aid', "crop's seed first; stock deducted", 'Status: Aid Allocated; farmer notified'))
    b.append(flow([(560, 760), (512, 760)]))
    b.append(step(410, 730, 60, 'Hand out the aid', 'farmer claims it at the MAO'))
    b.append(flow([(410, 790), (410, 838)]))
    b.append(step(410, 840, 56, 'Mark aid as claimed', 'aid ledger updated', 'Farmer notified'))
    b.append(flow([(310, 868), (262, 868)]))
    b.append(step(160, 840, 56, 'Receive the aid', 'seeds, fertilizer or cash aid'))
    b.append(flow([(160, 896), (160, 926)]))
    b.append(terminal(160, 948, 'End'))
    return ''.join(b)

w, h = round(W * S), round(H * S)
svg = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}">'
       f'{DEFS}<rect width="{w}" height="{h}" fill="white"/>{build()}</svg>')
with open(f'{OUTDIR}/J5_process_flowchart.svg', 'w', encoding='utf-8') as f:
    f.write(svg)
print('written', w, 'x', h)
