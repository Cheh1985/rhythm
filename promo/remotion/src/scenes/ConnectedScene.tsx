import {Easing, interpolate, useCurrentFrame} from "remotion";
import {SceneShell} from "../SceneShell";
import {clamp, colors} from "../theme";

const tools = [["01", "Read training history"], ["02", "Draft the next plan"], ["03", "Preview the impact"]];

export const ConnectedScene: React.FC = () => {
  const frame = useCurrentFrame();
  return (
    <SceneShell eyebrow="WITH WEBMCP" accent="lime">
      <div style={{position: "absolute", left: 92, top: 180, width: 920}}>
        <div style={{fontSize: 104, lineHeight: 0.98, letterSpacing: -6, fontWeight: 880}}>One connected<br /><span style={{color: colors.lime}}>conversation.</span></div>
        <div style={{fontSize: 36, lineHeight: 1.35, color: colors.muted, marginTop: 42, maxWidth: 760}}>AI works with fresh, structured workout data through task-focused tools.</div>
        <div style={{display: "inline-flex", marginTop: 54, padding: "16px 22px", borderRadius: 999, color: colors.lime, border: `1px solid ${colors.lime}66`, fontSize: 28, fontWeight: 800}}>17 semantic tools</div>
      </div>
      <div style={{position: "absolute", right: 100, top: 210, width: 660, display: "grid", gap: 22}}>
        {tools.map(([number, label], index) => {
          const start = 22 + index * 22;
          return <div key={number} style={{display: "flex", alignItems: "center", gap: 25, padding: "30px 34px", borderRadius: 24, background: "#f4f6ef", color: colors.ink, boxShadow: "0 24px 70px #0005", opacity: interpolate(frame, [start, start + 18], [0, 1], clamp), translate: `${interpolate(frame, [start, start + 18], [70, 0], {...clamp, easing: Easing.bezier(0.16, 1, 0.3, 1)})}px 0`}}><div style={{fontSize: 23, fontWeight: 900, color: colors.green}}>{number}</div><div style={{fontSize: 34, fontWeight: 780}}>{label}</div><div style={{marginLeft: "auto", color: colors.green, fontSize: 31}}>✓</div></div>;
        })}
      </div>
    </SceneShell>
  );
};
