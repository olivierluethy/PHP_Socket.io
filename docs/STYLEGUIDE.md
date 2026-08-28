# TuneVote Style Guide

The single source of truth for the visual system. Extracted verbatim from the
existing product (`tunevote_frontend`, Tailwind CSS v3.4.1, dark-only). Any UI
this project ships — demo client, admin/status views — must look like it was
always part of TuneVote. **Colours and text styling stay exactly as documented
here; never restyle the app on the side.**

Source of record: `tunevote_frontend/tailwind.config.js`, `src/index.css`,
`index.html`, and component classes.

---

## 1. Foundations

- **Framework:** Tailwind (utility-first), `darkMode: "class"`, but the app is
  **dark-only**. `html/body/#root` are forced to `bg-slate-950 text-white`
  (`index.css`). Slate-950 (`#020617`) is the universal canvas. No light theme.
- **Aesthetic:** neon-tinted **glassmorphism** — translucent white fills over a
  dark slate/purple gradient, hairline borders, backdrop blur, purple→pink
  gradients for emphasis.

---

## 2. Colour tokens

### Brand — Purple (primary)
`50 #f5f3ff · 100 #ede9fe · 200 #ddd6fe · 300 #c4b5fd · 400 #a78bfa · `
**`500 #8b5cf6`** (primary) `· 600 #7c3aed · 700 #6d28d9 · 800 #5b21b6 · 900 #4c1d95`

### Brand — Pink (accent)
`50 #fdf2f8 · 100 #fce7f3 · 200 #fbcfe8 · 300 #f9a8d4 · 400 #f472b6 · `
**`500 #ec4899`** (accent) `· 600 #db2777 · 700 #be185d · 800 #9f174d · 900 #831843`

### Neon
`neon.purple #a855f7 · neon.pink #ec4899 · neon.cyan #06b6d4`

### Semantic (Tailwind default palette in use)
| Role | Value |
|---|---|
| Canvas / page bg | `slate-950` `#020617` |
| Elevated surface (modal) | `slate-900` `#0f172a` |
| Glass surface fill | `white/5`, `white/10` |
| Text primary | `white` |
| Text muted | `white/30`–`white/50`, `gray-200` labels |
| Border hairline | `white/5`, `white/10`, `white/15` |
| Primary action | gradient `from-purple-500 to-pink-500` |
| Danger | bg `red-500/10`, text `red-400`, border `red-500/20` |
| Success / live | `emerald-400`/`emerald-500`, live dot `green-400` |
| Warning | `amber-500`/`amber-400` |

### Signature gradients
- Page bg: `bg-gradient-to-br from-slate-950 via-purple-950 to-slate-950`
- Heading text: `from-purple-400 via-pink-400 to-cyan-400` + `bg-clip-text text-transparent`
- Hero: `linear-gradient(135deg,#667eea 0%,#764ba2 100%)` (`tunevote-hero`)
- Card tint: `linear-gradient(135deg,rgba(139,92,246,.1),rgba(236,72,153,.1))` (`tunevote-card`)

---

## 3. Typography

- **Family:** `Inter, system-ui, sans-serif` (Google Fonts, weights 300–900, `display=swap`).
- **Base:** 16px, line-height 1.5, weight 400.
- **Headings:** `text-4xl md:text-5xl font-bold` (hero), `text-2xl font-bold`,
  `text-xl font-semibold`; gradient-clipped titles common; `tracking-tight`.
- **Body / UI:** `text-sm` and `text-xs` dominate controls. Labels
  `text-sm font-medium text-gray-200`.
- **Weights:** 500 medium, 600 semibold, 700 bold (700/800/900 for display).
- **Letter-spacing:** `tracking-tight` (headings), `tracking-wider`/`widest`
  (badges/labels). **Line-height:** `leading-none`/`tight`/`snug`/`relaxed`.

---

## 4. Spacing, radii, shadows, borders

- **Spacing:** default Tailwind scale. Common: `p-4/5/6`, `px-2 py-1`, `py-2.5`, `py-3`.
- **Radii:** `rounded-lg`/`xl` (inputs, buttons), `rounded-2xl`/`3xl` (cards, modals),
  `rounded-full` (pills, dots, avatars). Extended: `4xl 2rem`, `5xl 3rem`.
- **Shadows:** `glass 0 8px 32px rgba(31,38,135,.37)`,
  `glass-lg 0 25px 50px -12px rgba(139,92,246,.25)`,
  `neon 0 0 20px rgba(139,92,246,.5)`, `neon-lg 0 0 40px rgba(236,72,153,.6)`;
  also `shadow-2xl`, `hover:shadow-lg hover:shadow-purple-500/25`.
- **Backdrop blur:** `backdrop-blur-xl` (glass surfaces), `backdrop-blur-sm` (modal scrim), `xs 2px`.
- **Borders:** translucent hairlines `border border-white/10`; dashed `border-dashed border-white/15`.

---

## 5. Component patterns

- **Primary button:** `w-full py-3 rounded-xl bg-gradient-to-r from-purple-500 to-pink-500 font-semibold text-sm flex items-center justify-center gap-2 hover:shadow-lg hover:shadow-purple-500/25 transition-all disabled:opacity-70 disabled:cursor-not-allowed`.
- **Secondary / ghost:** `py-2.5 px-3 rounded-xl bg-white/5 border border-white/10 text-white text-sm font-medium hover:bg-white/10 transition-all`.
- **Icon button:** `p-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors`.
- **Warning button:** `bg-amber-500 text-white`.
- **Input:** `w-full px-4 py-2.5 rounded-xl bg-white/5 border border-white/10 text-white text-sm placeholder-white/30 focus:border-purple-400 focus:outline-none transition-colors` (some add `focus:ring-2 focus:ring-purple-400/50`).
- **Card:** `backdrop-blur-xl bg-white/5 rounded-2xl p-5 border border-white/10 shadow-2xl`.
- **Modal:** scrim `fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4`; panel `bg-slate-900 rounded-3xl p-6 max-w-sm w-full border border-white/10 shadow-2xl` (mobile = bottom sheet `items-end sm:items-center`).
- **Badge / pill:** count `rounded-full bg-purple-500 text-[10px] font-bold`; live dot `w-1.5 h-1.5 bg-green-400 rounded-full animate-pulse` (+ `animate-ping` ring, `motion-reduce:animate-none`).
- **Sticky header:** `sticky top-0 z-40 backdrop-blur-xl bg-slate-950/90 border-b border-white/5`.

---

## 6. Motion

- Icons: `lucide-react`. Animation lib: `framer-motion`.
- Tailwind keyframes: `float`/`float-slow`, `pulse-slow`/`pulse-fast`, `spin-slow`,
  `bounce-slow`, `glow`; mini-player `equalize`/`shimmer`/`ambient-glow`.
- Convention: `transition-all` / `transition-colors` on interactive elements;
  `active:scale-90` on tappable emoji; `.animate-fade-in` (0.25s ease-out);
  always respect `motion-reduce:animate-none`.

---

## 7. Applying this to non-React surfaces (demo / status pages)

The module ships plain HTML/CSS demo & status pages (no Tailwind build). Mirror
the system with hand-written CSS using these exact values so they read as
TuneVote: canvas `#020617`, surface `rgba(255,255,255,.05)` with
`1px solid rgba(255,255,255,.1)` and `backdrop-filter: blur(24px)`, Inter,
white text, purple→pink gradient (`#8b5cf6`→`#ec4899`) for primary actions,
`border-radius` 12–24px, live dot `#4ade80` with a pulse. Never introduce new
hues or a light theme.
