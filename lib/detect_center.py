#!/usr/bin/env python3
"""Analyze the first usable frame of a studio clip for normalization:
  - crop rectangle that removes black borders/wedges (auto-crop artifacts)
  - person horizontal centre (background subtraction vs #316CA4) in CROPPED coords
  - eye line y (OpenCV Haar face+eye) in CROPPED coords, for top-margin anchoring

Prints JSON: {ok, w, h, crop:{x,y,w,h}, cx, eye_y, eye_src}
All of cx/eye_y are relative to the cropped frame. Exit 0 always."""
import sys, json

BG_BGR = (164, 108, 49)   # #316CA4 in BGR
FG_THRESH = 45            # colour distance from background -> foreground (person)
BLACK_THRESH = 26         # max channel below this -> black border pixel
COL_FRAC = 0.02

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "no path"})); return
    import cv2, numpy as np
    cap = cv2.VideoCapture(sys.argv[1])
    out = {"ok": False}
    for _ in range(8):
        ret, frame = cap.read()
        if not ret:
            break
        h, w = frame.shape[:2]

        # ---- 1. crop away black borders (incl. wedges) ----
        bright = frame.max(axis=2)
        nonblack = (bright >= BLACK_THRESH).astype(np.uint8)
        nonblack = cv2.morphologyEx(nonblack, cv2.MORPH_OPEN, np.ones((5, 5), np.uint8))
        rows_any = nonblack.any(axis=1)
        cols_any = nonblack.any(axis=0)
        if not rows_any.any() or not cols_any.any():
            continue
        # Left/right: percentile of per-row first/last non-black column. This removes
        # diagonal wedges (rotation artifacts) that aren't full-height, capped at 20%.
        first_col = np.argmax(nonblack, axis=1)
        last_col  = w - 1 - np.argmax(nonblack[:, ::-1], axis=1)
        cl = min(int(np.percentile(first_col[rows_any], 99)), w // 5)
        cr = max(int(np.percentile(last_col[rows_any], 1)), w - 1 - w // 5)
        # Top/bottom: only strip FULL-WIDTH black bars (>80% of kept columns black per
        # row) so corner wedges (already handled by the left/right crop) don't over-crop.
        band = nonblack[:, cl:cr + 1]
        row_keep = band.mean(axis=1) >= 0.20          # row is content, not a black bar
        kept = np.where(row_keep)[0]
        if kept.size == 0: cl, ct, cr, cb = 0, 0, w - 1, h - 1
        else:
            ct = min(int(kept[0]), h // 5)
            cb = max(int(kept[-1]), h - 1 - h // 5)
        if cr <= cl or cb <= ct: cl, ct, cr, cb = 0, 0, w - 1, h - 1
        crop = {"x": cl, "y": ct, "w": cr - cl + 1, "h": cb - ct + 1}
        sub = frame[ct:cb + 1, cl:cr + 1]
        ch, cw = sub.shape[:2]

        # ---- 2. person horizontal centre on the cropped frame ----
        diff = np.abs(sub.astype(np.int16) - np.array(BG_BGR, dtype=np.int16))
        fg = (diff.max(axis=2) > FG_THRESH).astype(np.uint8)
        fg = cv2.morphologyEx(fg, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
        cols = fg.sum(axis=0); rows = fg.sum(axis=1)
        occ_cols = np.where(cols > ch * COL_FRAC)[0]
        occ_rows = np.where(rows > cw * COL_FRAC)[0]
        if occ_cols.size == 0 or occ_rows.size == 0:
            continue
        cx = (int(occ_cols[0]) + int(occ_cols[-1])) / 2
        head_top = int(occ_rows[0])

        # ---- 3. eye line via Haar FACE box, medianed over several frames ----
        # (Face detection is far more stable than the eye cascade. Eyes sit ~42% down
        #  the frontal-face box.) Sample frames across the clip for robustness.
        fc = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
        minf = max(80, cw // 12)
        def eye_from(gray_sub):
            faces = fc.detectMultiScale(gray_sub, 1.1, 5, minSize=(minf, minf))
            if not len(faces): return None
            fx, fy, fw, fh = sorted(faces, key=lambda f: f[2] * f[3])[-1]
            return fy + 0.42 * fh
        samples = [cv2.cvtColor(sub, cv2.COLOR_BGR2GRAY)]
        total = int(cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
        if total > 5:
            for fr in (int(total * .3), int(total * .5), int(total * .7), int(total * .9)):
                cap.set(cv2.CAP_PROP_POS_FRAMES, fr)
                ok2, fr2 = cap.read()
                if ok2:
                    samples.append(cv2.cvtColor(fr2[ct:cb + 1, cl:cr + 1], cv2.COLOR_BGR2GRAY))
        eyes = [e for e in (eye_from(g) for g in samples) if e is not None]
        if eyes:
            eyes.sort(); eye_y = eyes[len(eyes) // 2]; eye_src = "face(%d)" % len(eyes)
        else:
            eye_y = head_top + 0.12 * ch; eye_src = "estimate"

        out = {"ok": True, "w": w, "h": h, "crop": crop,
               "cx": round(cx, 1), "eye_y": round(float(eye_y), 1), "eye_src": eye_src}
        break
    cap.release()
    print(json.dumps(out))

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}))
