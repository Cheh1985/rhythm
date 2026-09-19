import {interpolate, useCurrentFrame} from "remotion";
import {SceneShell} from "../SceneShell";
import {clamp, colors} from "../theme";

export const ContextScene: React.FC = () => {
  const frame = useCurrentFrame();
  return (
    <SceneShell eyebrow="THE CONTEXT PROBLEM">
      <div style={{position: "absolute", left: 92, right: 130, top: 245}}>
        <div style={{fontSize: 100, lineHeight: 1.03, letterSpacing: -5, fontWeight: 850, opacity: interpolate(frame, [5, 25], [0, 1], clamp), translate: `0 ${interpolate(frame, [5, 25], [42, 0], clamp)}px`}}>Your workout history<br />already has the answers.</div>
        <div style={{marginTop: 46, fontSize: 44, lineHeight: 1.3, color: colors.muted, opacity: interpolate(frame, [30, 50], [0, 1], clamp)}}>But AI usually starts with <span style={{color: colors.red, fontWeight: 800}}>zero context.</span></div>
      </div>
    </SceneShell>
  );
};
