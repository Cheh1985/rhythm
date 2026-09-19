import {Easing, interpolate, useCurrentFrame} from "remotion";
import {SceneShell} from "../SceneShell";
import {clamp, colors} from "../theme";

export const ClosingScene: React.FC = () => {
  const frame = useCurrentFrame();
  return (
    <SceneShell accent="lime">
      <div style={{position: "absolute", inset: 0, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", textAlign: "center", opacity: interpolate(frame, [0, 22], [0, 1], clamp), scale: interpolate(frame, [0, 35], [0.94, 1], {...clamp, easing: Easing.bezier(0.16, 1, 0.3, 1)})}}>
        <div style={{fontSize: 27, fontWeight: 900, letterSpacing: 7, color: colors.lime}}>RHYTHM × WEBMCP</div>
        <div style={{fontSize: 102, lineHeight: 1.02, letterSpacing: -5, fontWeight: 880, marginTop: 38}}>Workout facts in.<br /><span style={{color: colors.mint}}>A safer next plan out.</span></div>
        <div style={{fontSize: 44, marginTop: 62, padding: "20px 36px", borderRadius: 999, background: colors.cream, color: colors.ink, fontWeight: 850}}>workout.sharedout.ru</div>
        <div style={{fontSize: 25, marginTop: 42, color: colors.muted, letterSpacing: 2}}>BUILT FOR THE OPENAI WEBMCP CHALLENGE</div>
      </div>
    </SceneShell>
  );
};
