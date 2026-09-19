import {Composition} from "remotion";
import {RhythmFilm, TOTAL_FRAMES} from "./RhythmFilm";

export const MyComposition: React.FC = () => {
  return (
    <Composition
      id="RhythmWebMCP"
      component={RhythmFilm}
      durationInFrames={TOTAL_FRAMES}
      fps={30}
      width={1920}
      height={1080}
    />
  );
};
