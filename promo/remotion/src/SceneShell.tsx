import type {ReactNode} from "react";
import {AbsoluteFill, interpolate, useCurrentFrame} from "remotion";
import {clamp, colors, font} from "./theme";

export const SceneShell: React.FC<{
  children: ReactNode;
  eyebrow?: string;
  accent?: "mint" | "lime" | "red";
}> = ({children, eyebrow, accent = "mint"}) => {
  const frame = useCurrentFrame();
  const glow = accent === "red" ? colors.red : accent === "lime" ? colors.lime : colors.mint;

  return (
    <AbsoluteFill style={{backgroundColor: colors.ink, color: colors.cream, fontFamily: font, overflow: "hidden"}}>
      <div style={{position: "absolute", width: 850, height: 850, borderRadius: 999, right: -250, top: -360, background: glow, filter: "blur(180px)", opacity: 0.16}} />
      <div style={{position: "absolute", width: 620, height: 620, borderRadius: 999, left: -260, bottom: -330, background: colors.green, filter: "blur(160px)", opacity: 0.22}} />
      <div style={{position: "absolute", inset: 0, backgroundImage: "linear-gradient(rgba(255,255,255,.026) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.026) 1px, transparent 1px)", backgroundSize: "54px 54px", opacity: 0.45}} />
      {eyebrow ? (
        <div style={{position: "absolute", left: 92, top: 72, fontSize: 25, fontWeight: 800, letterSpacing: 5, color: glow, opacity: interpolate(frame, [0, 16], [0, 1], clamp)}}>{eyebrow}</div>
      ) : null}
      <div style={{position: "absolute", right: 92, top: 70, display: "flex", alignItems: "center", gap: 14, fontSize: 26, fontWeight: 800, letterSpacing: 2}}>
        <div style={{width: 38, height: 38, borderRadius: 12, display: "grid", placeItems: "center", background: colors.mint, color: colors.ink}}>R</div>
        RHYTHM
      </div>
      {children}
    </AbsoluteFill>
  );
};
