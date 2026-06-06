#!/usr/bin/env python3
"""Analyze a studio clip for normalization:
  - crop rectangle that removes black borders/wedges (auto-crop artifacts)
  - person horizontal centre + TOP OF HEAD (background subtraction vs #316CA4),
    medianed over several frames for stability.

Prints JSON: {ok, w, h, crop:{x,y,w,h}, cx, head_top}
cx and head_top are in CROPPED coordinates. Exit 0 always."""
import sys, json

BG_BGR = (164, 108, 49)   # #316CA4 in BGR
FG_THRESH = 45            # colour distance from background -> foreground (person)
BLACK_THRESH = 26         # max channel below this -> black border pixel
COL_FRAC = 0.02

def analyze(sub, np, cv2):
    """Return (cx, head_top) of the person within the cropped frame, or None."""
    ch, cw = sub.shape[:2]
    diff = np.abs(sub.astype(np.int16) - np.array(BG_BGR, dtype=np.int16))
    fg = (diff.max(axis=2) > FG_THRESH).astype(np.uint8)
    fg = cv2.morphologyEx(fg, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    cols = fg.sum(axis=0); rows = fg.sum(axis=1)
    occ_cols = np.where(cols > ch * COL_FRAC)[0]
    occ_rows = np.where(rows > cw * COL_FRAC)[0]
    if occ_cols.size == 0 or occ_rows.size == 0:
        return None
    return (int(occ_cols[0]) + int(occ_cols[-1])) / 2, int(occ_rows[0])

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "no path"})); return
    import cv2, numpy as np
    cap = cv2.VideoCapture(sys.argv[1])
    out = {"ok": False}

    frame = None
    for _ in range(8):
        ret, fr = cap.read()
        if not ret:
            break
        frame = fr
        break
    if frame is None:
        print(json.dumps(out)); return
    h, w = frame.shape[:2]

    # ---- crop away black borders (incl. corner wedges) ----
    bright = frame.max(axis=2)
    nonblack = (bright >= BLACK_THRESH).astype(np.uint8)
    nonblack = cv2.morphologyEx(nonblack, cv2.MORPH_OPEN, np.ones((5, 5), np.uint8))
    rows_any = nonblack.any(axis=1)
    if not rows_any.any():
        print(json.dumps(out)); return
    first_col = np.argmax(nonblack, axis=1)
    last_col  = w - 1 - np.argmax(nonblack[:, ::-1], axis=1)
    cl = min(int(np.percentile(first_col[rows_any], 99)), w // 5)
    cr = max(int(np.percentile(last_col[rows_any], 1)), w - 1 - w // 5)
    band = nonblack[:, cl:cr + 1]
    row_keep = band.mean(axis=1) >= 0.20          # full-width black bars only
    kept = np.where(row_keep)[0]
    if kept.size == 0:
        cl, ct, cr, cb = 0, 0, w - 1, h - 1
    else:
        ct = min(int(kept[0]), h // 5)
        cb = max(int(kept[-1]), h - 1 - h // 5)
    if cr <= cl or cb <= ct:
        cl, ct, cr, cb = 0, 0, w - 1, h - 1
    crop = {"x": cl, "y": ct, "w": cr - cl + 1, "h": cb - ct + 1}

    # ---- person centre + head-top, medianed over sampled frames ----
    def sub_of(fr): return fr[ct:cb + 1, cl:cr + 1]
    samples = [sub_of(frame)]
    total = int(cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
    if total > 5:
        for f in (int(total * .25), int(total * .5), int(total * .75)):
            cap.set(cv2.CAP_PROP_POS_FRAMES, f)
            ok2, fr2 = cap.read()
            if ok2:
                samples.append(sub_of(fr2))
    res = [r for r in (analyze(s, np, cv2) for s in samples) if r is not None]
    cap.release()
    if not res:
        print(json.dumps(out)); return
    cxs = sorted(r[0] for r in res)
    tops = sorted(r[1] for r in res)
    cx = cxs[len(cxs) // 2]
    head_top = tops[len(tops) // 2]

    out = {"ok": True, "w": w, "h": h, "crop": crop,
           "cx": round(cx, 1), "head_top": round(float(head_top), 1)}
    print(json.dumps(out))

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}))
