import {interpolate, useCurrentFrame} from "remotion";
import {SceneShell} from "../SceneShell";
import {clamp, colors} from "../theme";

const steps = ["Export", "Download", "Open chat", "Upload", "Analyze", "Import"];

export const ManualScene: React.FC = () => {
  const frame = useCurrentFrame();
  return (
    <SceneShell eyebrow="BEFORE WEBMCP" accent="red">
      <div style={{position: "absolute", left: 92, right: 92, top: 205}}>
        <div style={{fontSize: 88, lineHeight: 1.05, letterSpacing: -4, fontWeight: 850}}>Six manual steps.<br /><span style={{color: colors.red}}>Every single time.</span></div>
        <div style={{display: "flex", alignItems: "center", gap: 13, marginTop: 90}}>
          {steps.map((step, index) => {
            const start = 30 + index * 14;
            return <div key={step} style={{display: "flex", alignItems: "center", gap: 13, opacity: interpolate(frame, [start, start + 10], [0, 1], clamp), translate: `${interpolate(frame, [start, start + 10], [22, 0], clamp)}px 0`}}><div style={{padding: "24px 26px", borderRadius: 18, border: "1px solid #ffffff2e", background: "#ffffff0b", fontSize: 27, fontWeight: 750, whiteSpace: "nowrap"}}>{step}</div>{index < steps.length - 1 ? <div style={{fontSize: 28, color: colors.muted}}>→</div> : null}</div>;
          })}
        </div>
        <div style={{marginTop: 58, fontSize: 35, color: colors.muted, opacity: interpolate(frame, [120, 145], [0, 1], clamp)}}>Copying files is not coaching.</div>
      </div>
    </SceneShell>
  );
};
