"""Build TaskPe-App-Listing.docx from APP-LISTING.md.

APP-LISTING.md stays the source of truth (generated and length-checked by
tools/listing-copy.py). This script only lays the same content out for a person
filling the Shopify forms in a word processor: headings, real tables, shaded
"paste exactly this" boxes, page numbers, and an appendix repeating every
pasteable block in form order.

Needs: python3 -m pip install --break-system-packages python-docx
Run from the repo root:  python3 tools/make-docx.py
"""

import re

from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_TAB_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor

SRC = 'APP-LISTING.md'
OUT = 'TaskPe-App-Listing.docx'

BRAND = RGBColor(0x0B, 0x5C, 0x57)      # deep teal, matches the app icon
INK = RGBColor(0x20, 0x24, 0x28)
MUTED = RGBColor(0x5A, 0x63, 0x6B)
BOX_FILL = 'F2F4F5'
HEAD_FILL = 'E3EDEB'


# ----------------------------------------------------------------- low level --
def _shd(pr, fill):
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'), 'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'), fill)
    pr.append(shd)


def shade_cell(tc, fill):
    """Background colour for a table cell."""
    _shd(tc.get_or_add_tcPr(), fill)


def shade_paragraph(par, fill):
    """Background colour for a paragraph."""
    _shd(par._p.get_or_add_pPr(), fill)


def borders(par, fill=BOX_FILL):
    """A thin box around a paragraph, used for the paste blocks."""
    pPr = par._p.get_or_add_pPr()
    box = OxmlElement('w:pBdr')
    for side in ('top', 'left', 'bottom', 'right'):
        line = OxmlElement('w:' + side)
        line.set(qn('w:val'), 'single')
        line.set(qn('w:sz'), '6')
        line.set(qn('w:space'), '6')
        line.set(qn('w:color'), 'BFC7C9')
        box.append(line)
    pPr.append(box)
    _shd(pPr, fill)


def start_here(doc):
    """The three things a human has to decide, before any of the copy."""
    box = doc.add_table(rows=1, cols=1)
    box.style = 'Table Grid'
    cell = box.rows[0].cells[0]
    cell.text = ''
    shade_cell(cell._tc, 'FBF6E7')
    head = cell.paragraphs[0]
    head.paragraph_format.space_before = Pt(3)
    rich(head, 'Start here — 3 decisions before you paste anything', size=11, color=BRAND)
    for run in head.runs:
        run.bold = True
    items = [
        ('Your links do not exist yet.', 'Shopify requires a privacy policy URL and expects terms of '
         'service, a help page and a support email. /privacy is live in the app; /terms is not. Fill the '
         'bracketed placeholders in section 2 before submitting.'),
        ('One data decision.', 'The order search needs Level 1 only. The picker’s Customer tab reads a '
         'name, which is Level 2 and a slower review. Section 4 gives both answers; option A (drop the '
         'customer tab) is the recommended one and is four one-line edits.'),
        ('Do not quote a price anywhere in the images.', 'Listing images must not carry pricing. The app '
         'also stopped inventing prices: the Plan tab now shows what Shopify bills, in the store’s '
         'currency. Keep prices in the Pricing section only.'),
    ]
    for title, body in items:
        par = cell.add_paragraph()
        par.paragraph_format.space_before = Pt(4)
        rich(par, title + ' ', size=10)
        for run in par.runs:
            run.bold = True
        rich(par, body, size=10)
    doc.add_paragraph().paragraph_format.space_after = Pt(4)


def page_numbers(doc, label):
    footer = doc.sections[0].footer.paragraphs[0]
    footer.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = footer.add_run(label + '   ·   page ')
    run.font.size = Pt(8)
    run.font.color.rgb = MUTED
    fld = OxmlElement('w:fldSimple')
    fld.set(qn('w:instr'), 'PAGE')
    footer._p.append(fld)


INLINE = re.compile(r'(\*\*.+?\*\*|`[^`]+`|\*[^*]+?\*|\[[^\]]+\]\([^)]+\))')


def rich(par, text, size=10.5, color=None, mono_for_code=True):
    """Write text into a paragraph, honouring **bold**, `code`, *italic*, [t](u)."""
    for chunk in INLINE.split(text):
        if not chunk:
            continue
        run = par.add_run()
        run.font.size = Pt(size)
        if color is not None:
            run.font.color.rgb = color
        if chunk.startswith('**') and chunk.endswith('**'):
            run.text = chunk[2:-2]
            run.bold = True
        elif chunk.startswith('`') and chunk.endswith('`'):
            run.text = chunk[1:-1]
            if mono_for_code:
                run.font.name = 'Consolas'
                run.font.size = Pt(size - 0.5)
        elif chunk.startswith('*') and chunk.endswith('*') and len(chunk) > 2:
            run.text = chunk[1:-1]
            run.italic = True
        elif chunk.startswith('['):
            m = re.match(r'\[([^\]]+)\]\(([^)]+)\)', chunk)
            if m:
                run.text = '%s (%s)' % (m.group(1), m.group(2))
                run.font.color.rgb = BRAND
                run.underline = True
            else:
                run.text = chunk
        else:
            run.text = chunk


def heading(doc, text, level):
    par = doc.add_paragraph()
    par.paragraph_format.space_before = Pt(14 if level == 1 else 10)
    par.paragraph_format.space_after = Pt(4)
    par.paragraph_format.keep_with_next = True
    rich(par, text, size=15 if level == 1 else (13 if level == 2 else 11.5), color=BRAND)
    for run in par.runs:
        run.bold = True
    if level == 1:
        pPr = par._p.get_or_add_pPr()
        bottom = OxmlElement('w:pBdr')
        line = OxmlElement('w:bottom')
        line.set(qn('w:val'), 'single')
        line.set(qn('w:sz'), '8')
        line.set(qn('w:space'), '3')
        line.set(qn('w:color'), '9CBDB8')
        bottom.append(line)
        pPr.append(bottom)


def mono_run(par, text, size=9.5):
    par.paragraph_format.space_before = Pt(0)
    par.paragraph_format.space_after = Pt(0)
    run = par.add_run(text if text else ' ')
    run.font.name = 'Consolas'
    run.font.size = Pt(size)
    run.font.color.rgb = INK


def paste_box(doc, lines, title=None):
    """A boxed, monospace block: select it, copy it, paste it into the form.

    Long blocks (the review instructions) become one real one-cell table instead of
    sixty bordered paragraphs — same copy-verbatim look, and the document stays light
    enough that Word lays it out without stalling.
    """
    body = list(lines)
    while body and not body[0].strip():
        body.pop(0)
    while body and not body[-1].strip():
        body.pop()
    if title:
        cap = doc.add_paragraph()
        cap.paragraph_format.space_after = Pt(2)
        rich(cap, title, size=9, color=MUTED)
        for run in cap.runs:
            run.bold = True
    if len(body) > 22:
        table = doc.add_table(rows=1, cols=1)
        table.style = 'Table Grid'
        table.alignment = WD_TABLE_ALIGNMENT.CENTER
        cell = table.rows[0].cells[0]
        cell.text = ''
        shade_cell(cell._tc, BOX_FILL)
        for n, ln in enumerate(body):
            mono_run(cell.paragraphs[0] if n == 0 else cell.add_paragraph(), ln, size=9)
        doc.add_paragraph().paragraph_format.space_after = Pt(4)
        return

    for ln in body:
        par = doc.add_paragraph()
        par.paragraph_format.space_before = Pt(0)
        par.paragraph_format.space_after = Pt(0)
        par.paragraph_format.left_indent = Inches(0.12)
        par.paragraph_format.right_indent = Inches(0.12)
        borders(par)
        run = par.add_run(ln if ln else ' ')
        run.font.name = 'Consolas'
        run.font.size = Pt(9.5)
        run.font.color.rgb = INK
    doc.add_paragraph().paragraph_format.space_after = Pt(2)


def add_table(doc, rows):
    n_col = max(len(r) for r in rows)
    table = doc.add_table(rows=0, cols=n_col)
    table.style = 'Table Grid'
    table.alignment = WD_TABLE_ALIGNMENT.LEFT
    table.autofit = True
    for i, cells in enumerate(rows):
        row = table.add_row()
        for j in range(n_col):
            cell = row.cells[j]
            cell.text = ''
            par = cell.paragraphs[0]
            par.paragraph_format.space_before = Pt(2)
            par.paragraph_format.space_after = Pt(2)
            txt = cells[j] if j < len(cells) else ''
            if i == 0:
                shade_cell(cell._tc, HEAD_FILL)
                rich(par, txt, size=9.5)
                for run in par.runs:
                    run.bold = True
            else:
                rich(par, txt, size=9.5)
        if i == 0:
            trPr = row._tr.get_or_add_trPr()
            hdr = OxmlElement('w:tblHeader')
            hdr.set(qn('w:val'), 'true')
            trPr.append(hdr)
    # A little air after every table.
    doc.add_paragraph().paragraph_format.space_after = Pt(2)
    return table


def split_row(line):
    line = line.strip()
    if line.startswith('|'):
        line = line[1:]
    if line.endswith('|'):
        line = line[:-1]
    return [c.strip() for c in line.split('|')]


# -------------------------------------------------------------------- build ----
def build():
    src = open(SRC, encoding='utf-8').read().split('\n')

    doc = Document()
    normal = doc.styles['Normal']
    normal.font.name = 'Calibri'
    normal.font.size = Pt(10.5)
    normal.font.color.rgb = INK
    normal.paragraph_format.space_after = Pt(5)
    for section in doc.sections:
        section.left_margin = section.right_margin = Inches(0.7)
        section.top_margin = Inches(0.6)
        section.bottom_margin = Inches(0.6)
    page_numbers(doc, 'TaskPe — Shopify App Store listing')

    props = doc.core_properties
    props.title = 'TaskPe — Shopify App Store listing & protected customer data answers'
    props.subject = 'Paste-ready Shopify Partner Dashboard submission content'
    props.author = 'TaskPe'
    props.comments = ('Generated from APP-LISTING.md by tools/make-docx.py. Field limits are '
                      'enforced by tools/listing-copy.py.')

    # Caption scan: a fenced block is labelled by the last plain sentence above it that ends with a
    # colon (a blank line in between is allowed), because that is how the markdown writes them.
    fence_open, captions, skip = [], {}, set()
    depth = 0
    for n, ln in enumerate(src):
        if ln.strip().startswith('```'):
            depth ^= 1
            if depth:
                fence_open.append(n)
        elif not depth:
            stripped = src[n].strip()
            if stripped.endswith(':') and not stripped.startswith(('#', '|', '>')) and len(stripped) < 130:
                j = n
                while True:
                    j += 1
                    if j < len(src) and not src[j].strip():
                        continue
                    break
                if j < len(src) and src[j].strip().startswith('```'):
                    captions[j] = re.sub(r'[*`]', '', stripped).rstrip(':').strip()
                    skip.add(n)

    i = 0
    in_code = False
    code_buf = []
    code_cap = None
    table_buf = []
    last_heading = ''
    pasted = []          # (label, lines) of every copy-verbatim block in the file

    def flush_table():
        nonlocal table_buf
        if table_buf:
            rows = [split_row(r) for r in table_buf if not re.match(r'^\|[\s:\-\|]+\|?$', r.strip())]
            rows = [r for r in rows if any(c for c in r)]
            if rows:
                add_table(doc, rows)
            table_buf = []

    while i < len(src):
        line = src[i].rstrip()
        if i in skip:
            i += 1
            continue

        if line.strip().startswith('```'):
            flush_table()
            if in_code:
                paste_box(doc, code_buf, code_cap)
                pasted.append((code_cap or last_heading, list(code_buf)))
                code_buf, code_cap = [], None
            else:
                code_cap = captions.get(i)
            in_code = not in_code
            i += 1
            continue
        if in_code:
            code_buf.append(line)
            i += 1
            continue

        if line.strip().startswith('|'):
            table_buf.append(line)
            i += 1
            continue
        flush_table()

        if re.match(r'^#{1,6} ', line):
            level = len(line) - len(line.lstrip('#'))
            text = line[level + 1:].strip()
            last_heading = re.sub(r'[*`]', '', text)
            if level == 1:
                # The file's H1 becomes the document title block.
                par = doc.add_paragraph()
                par.paragraph_format.space_after = Pt(2)
                rich(par, text, size=21, color=BRAND)
                for run in par.runs:
                    run.bold = True
                sub = doc.add_paragraph()
                rich(sub, 'Word version of APP-LISTING.md — every field below has been length-checked '
                          'against Shopify’s limits. Grey boxes are for copying verbatim.', size=9.5, color=MUTED)
                for run in sub.runs:
                    run.italic = True
                start_here(doc)
            else:
                if level == 2 and re.match(r'^4\.\s', text):
                    doc.add_page_break()
                heading(doc, text, level)
            i += 1
            continue

        if line.strip() == '---':
            i += 1
            continue

        m = re.match(r'^(\d+)\.\s+(.*)$', line.strip())
        if m:
            par = doc.add_paragraph(style='List Number')
            par.paragraph_format.space_after = Pt(3)
            rich(par, m.group(2))
            i += 1
            continue

        if line.strip().startswith(('* ', '- ')):
            par = doc.add_paragraph(style='List Bullet')
            par.paragraph_format.space_after = Pt(3)
            rich(par, line.strip()[2:])
            i += 1
            continue

        if line.strip().startswith('>'):
            par = doc.add_paragraph()
            par.paragraph_format.left_indent = Inches(0.25)
            rich(par, line.strip().lstrip('> ').strip(), size=10, color=MUTED)
            for run in par.runs:
                run.italic = True
            i += 1
            continue

        if line.strip():
            par = doc.add_paragraph()
            rich(par, line.strip())
            i += 1
            continue

        i += 1

    flush_table()

    # ---- Appendix: every pasteable block, in the order the forms ask for them ----
    doc.add_page_break()
    heading(doc, 'Appendix — the paste sheet', 1)
    intro = doc.add_paragraph()
    rich(intro, 'Everything you type into Shopify, in one place and in form order. Copy the text inside '
                'each box exactly; the number in brackets is the character count against the field limit.',
         size=10, color=MUTED)

    # Only form fields get a second life in the appendix. The review instructions (7,000
    # characters) live in their own section, and repeating them here would bury the short ones.
    pasted = [x for x in pasted if sum(len(l) + 1 for l in x[1]) <= 2600]
    for n, (cap, buf) in enumerate(pasted, 1):
        lines = list(buf)
        while lines and not lines[0].strip():
            lines.pop(0)
        while lines and not lines[-1].strip():
            lines.pop()
        if not lines:
            continue
        first = lines[0].strip()
        if first.endswith(':') and '(' in first:
            label = lines.pop(0).strip().rstrip(':')
        else:
            label = cap or 'Paste block'
        body = [ln for ln in lines]
        text = '\n'.join(body)
        label = re.sub(r'^\d+\.\s*', '', label or '') or 'Paste block'
        paste_box(doc, body, '%s. %s   [%d characters]' % (n, label, len(text)))

    note = doc.add_paragraph()
    rich(note, 'Still to fill before submitting: the links (privacy policy, terms of service, help centre, '
               'support email and phone, demo store), the pricing page for the WhatsApp charge, and the '
               'Level 1 / Level 2 decision in section 4. Section 8 lists what Shopify rejects and why.',
         size=10)
    for run in note.runs:
        run.bold = True

    doc.save(OUT)
    return OUT, len(pasted)


if __name__ == '__main__':
    out, n = build()
    print('wrote %s (%d paste blocks in the appendix)' % (out, n))
