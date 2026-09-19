import {Video} from "@remotion/media";
import {AbsoluteFill, Easing, interpolate, staticFile, useCurrentFrame} from "remotion";
import {clamp, colors, font} from "../theme";

export const IntroScene: React.FC = () => {
  const frame = useCurrentFrame();
  return (
    <AbsoluteFill style={{background: colors.ink, color: colors.cream, fontFamily: font, overflow: "hidden"}}>
      <div style={{position: "absolute", inset: -180, background: "radial-gradient(circle at 80% 10%, #2ca97955, transparent 45%), radial-gradient(circle at 10% 90%, #72deb333, transparent 42%)"}} />
      <div style={{position: "absolute", left: 55, right: 55, top: 42, height: 920, borderRadius: 30, overflow: "hidden", background: "#102d25", boxShadow: "0 32px 100px #000b", opacity: interpolate(frame, [0, 18], [0, 1], clamp), scale: interpolate(frame, [0, 60], [0.975, 1], {...clamp, easing: Easing.bezier(0.16, 1, 0.3, 1)})}}>
        <Video src={staticFile("intro.mp4")} muted objectFit="contain" style={{width: "100%", height: "100%", background: "#fff"}} />
      </div>
      <div style={{position: "absolute", left: 0, right: 0, bottom: 0, height: 360, background: "linear-gradient(transparent, #071a16 78%)"}} />
      <div style={{position: "absolute", left: 100, bottom: 74, fontSize: 64, lineHeight: 1.05, fontWeight: 850, letterSpacing: -2, opacity: interpolate(frame, [105, 135], [0, 1], clamp), translate: `0 ${interpolate(frame, [105, 135], [28, 0], clamp)}px`}}>Training shouldn&apos;t be guesswork.</div>
      <div style={{position: "absolute", right: 100, bottom: 82, color: colors.mint, fontSize: 27, fontWeight: 800, letterSpacing: 4}}>RHYTHM</div>
    </AbsoluteFill>
  );
};
