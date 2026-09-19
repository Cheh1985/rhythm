import {interpolate, useCurrentFrame} from "remotion";
import {SceneShell} from "../SceneShell";
import {clamp, colors} from "../theme";

const flow = ["READ", "DRAFT", "PREVIEW", "CONFIRM"];

export const ControlScene: React.FC = () => {
  const frame = useCurrentFrame();
  return (
    <SceneShell eyebrow="HUMAN IN CONTROL">
      <div style={{position: "absolute", left: 92, right: 92, top: 195}}>
        <div style={{fontSize: 112, fontWeight: 880, letterSpacing: -6, lineHeight: 1}}>AI proposes.<br /><span style={{color: colors.mint}}>You decide.</span></div>
        <div style={{display: "flex", alignItems: "center", gap: 24, marginTop: 95}}>
          {flow.map((item, index) => {
            const start = 35 + index * 22;
            const final = index === flow.length - 1;
            return <div key={item} style={{display: "flex", alignItems: "center", gap: 24, opacity: interpolate(frame, [start, start + 14], [0, 1], clamp)}}><div style={{padding: "25px 38px", borderRadius: 999, background: final ? colors.mint : "#ffffff0d", color: final ? colors.ink : colors.cream, border: final ? "none" : "1px solid #ffffff33", fontSize: 31, fontWeight: 900, letterSpacing: 2}}>{item}</div>{index < flow.length - 1 ? <div style={{fontSize: 35, color: colors.muted}}>→</div> : null}</div>;
          })}
        </div>
        <div style={{fontSize: 36, color: colors.muted, marginTop: 60, opacity: interpolate(frame, [125, 150], [0, 1], clamp)}}>Changes stay drafts until the athlete explicitly approves them.</div>
      </div>
    </SceneShell>
  );
};
