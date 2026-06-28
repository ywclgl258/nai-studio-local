"""生成 NAI Studio favicon.ico (16/32/48) + favicon.svg"""
import os
from PIL import Image, ImageDraw

OUT_DIR = r"D:\anima\nai-studio\public"
ICO_PATH = os.path.join(OUT_DIR, "favicon.ico")
SVG_PATH = os.path.join(OUT_DIR, "favicon.svg")
PNG_32 = os.path.join(OUT_DIR, "favicon-32.png")
PNG_192 = os.path.join(OUT_DIR, "favicon-192.png")
APPLE = os.path.join(OUT_DIR, "apple-touch-icon.png")

# 三段渐变：紫 → 蓝 → 青
def lerp(a, b, t):
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))

PURPLE = (168, 85, 247)
INDIGO = (99, 102, 241)
CYAN   = (6, 182, 212)
WHITE  = (255, 255, 255)


def render(size: int) -> Image.Image:
    """渲染 size×size 的图标：圆角渐变 + 双框 + 加号 + 中心圆"""
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    px = img.load()
    r = size * 0.22  # 圆角
    # 1) 圆角渐变背景
    for y in range(size):
        for x in range(size):
            # 渐变方向：左上 → 右下
            t = (x + y) / (2 * (size - 1))
            if t < 0.5:
                color = lerp(PURPLE, INDIGO, t * 2)
            else:
                color = lerp(INDIGO, CYAN, (t - 0.5) * 2)
            # 圆角裁剪
            cx, cy = min(x, size - 1 - x), min(y, size - 1 - y)
            in_round = True
            for cx0, cy0 in [(r, r), (size - 1 - r, r), (r, size - 1 - r), (size - 1 - r, size - 1 - r)]:
                if (cx0 == r and cy0 == r and x < r and y < r) or \
                   (cx0 == size - 1 - r and cy0 == r and x > size - 1 - r and y < r) or \
                   (cx0 == r and cy0 == size - 1 - r and x < r and y > size - 1 - r) or \
                   (cx0 == size - 1 - r and cy0 == size - 1 - r and x > size - 1 - r and y > size - 1 - r):
                    dx, dy = x - cx0, y - cy0
                    if dx * dx + dy * dy > r * r:
                        in_round = False
                        break
            if in_round:
                px[x, y] = color + (255,)
    draw = ImageDraw.Draw(img)
    # 2) 内框描边（描出小白边 1 像素）
    pad1 = max(1, int(size * 0.16))
    draw.rounded_rectangle([pad1, pad1, size - 1 - pad1, size - 1 - pad1],
                            radius=max(1, int(size * 0.13)),
                            outline=(255, 255, 255, 140), width=max(1, size // 32))
    # 3) 加号 + 中心点（NAI 的"交叉笔触"概念）
    cx0 = cy0 = size // 2
    arm = size * 0.22  # 加号半臂长
    thick = max(1, int(size * 0.13))
    # 竖
    draw.line([(cx0, cy0 - arm), (cx0, cy0 + arm)], fill=WHITE + (255,), width=thick)
    # 横
    draw.line([(cx0 - arm, cy0), (cx0 + arm, cy0)], fill=WHITE + (255,), width=thick)
    # 中心圆点（盖住交叉点，圆润感）
    r_dot = max(1, int(size * 0.09))
    draw.ellipse([cx0 - r_dot, cy0 - r_dot, cx0 + r_dot, cy0 + r_dot], fill=WHITE + (255,))
    return img


# 多尺寸 favicon.ico
sizes = [16, 32, 48, 64, 128, 256]
base = render(256)
base.save(ICO_PATH, format='ICO', sizes=[(s, s) for s in sizes])
base.resize((32, 32), Image.LANCZOS).save(PNG_32, 'PNG')
base.resize((192, 192), Image.LANCZOS).save(PNG_192, 'PNG')
base.resize((180, 180), Image.LANCZOS).save(APPLE, 'PNG')

# SVG（最优先，浏览器矢量）
svg = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
<defs>
  <linearGradient id="g" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
    <stop offset="0" stop-color="#a855f7"/>
    <stop offset="0.5" stop-color="#6366f1"/>
    <stop offset="1" stop-color="#06b6d4"/>
  </linearGradient>
</defs>
<rect x="2" y="2" width="28" height="28" rx="7" fill="url(#g)"/>
<rect x="5.5" y="5.5" width="21" height="21" rx="4" fill="none" stroke="#fff" stroke-opacity="0.55" stroke-width="1.2"/>
<path d="M16 9v14M9 16h14" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
<circle cx="16" cy="16" r="2.5" fill="#fff"/>
</svg>
'''
with open(SVG_PATH, 'w', encoding='utf-8') as f:
    f.write(svg)

print(f"OK: {ICO_PATH} ({os.path.getsize(ICO_PATH)} bytes)")
print(f"OK: {SVG_PATH} ({os.path.getsize(SVG_PATH)} bytes)")
print(f"OK: {APPLE}")