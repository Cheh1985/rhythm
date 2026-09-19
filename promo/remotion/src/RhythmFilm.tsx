import {TransitionSeries, linearTiming} from "@remotion/transitions";
import {fade} from "@remotion/transitions/fade";
import {slide} from "@remotion/transitions/slide";
import {IntroScene} from "./scenes/IntroScene";
import {ContextScene} from "./scenes/ContextScene";
import {ManualScene} from "./scenes/ManualScene";
import {ConnectedScene} from "./scenes/ConnectedScene";
import {ControlScene} from "./scenes/ControlScene";
import {ClosingScene} from "./scenes/ClosingScene";

const TRANSITION = 15;
export const TOTAL_FRAMES = 315 + 150 + 210 + 240 + 240 + 210 - TRANSITION * 5;

export const RhythmFilm: React.FC = () => (
  <TransitionSeries>
    <TransitionSeries.Sequence durationInFrames={315}><IntroScene /></TransitionSeries.Sequence>
    <TransitionSeries.Transition presentation={fade()} timing={linearTiming({durationInFrames: TRANSITION})} />
    <TransitionSeries.Sequence durationInFrames={150}><ContextScene /></TransitionSeries.Sequence>
    <TransitionSeries.Transition presentation={slide({direction: "from-right"})} timing={linearTiming({durationInFrames: TRANSITION})} />
    <TransitionSeries.Sequence durationInFrames={210}><ManualScene /></TransitionSeries.Sequence>
    <TransitionSeries.Transition presentation={fade()} timing={linearTiming({durationInFrames: TRANSITION})} />
    <TransitionSeries.Sequence durationInFrames={240}><ConnectedScene /></TransitionSeries.Sequence>
    <TransitionSeries.Transition presentation={slide({direction: "from-bottom"})} timing={linearTiming({durationInFrames: TRANSITION})} />
    <TransitionSeries.Sequence durationInFrames={240}><ControlScene /></TransitionSeries.Sequence>
    <TransitionSeries.Transition presentation={fade()} timing={linearTiming({durationInFrames: TRANSITION})} />
    <TransitionSeries.Sequence durationInFrames={210}><ClosingScene /></TransitionSeries.Sequence>
  </TransitionSeries>
);
