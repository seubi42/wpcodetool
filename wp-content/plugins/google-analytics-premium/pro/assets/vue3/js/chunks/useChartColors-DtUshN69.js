import { b as brandConfig } from "./monsterinsights-style-D8E96NnX.js";
function useChartColors() {
  const { colors, chartColors, name } = brandConfig;
  const getChartColors = (count = 10) => {
    if (count <= chartColors.length) {
      return chartColors.slice(0, count);
    }
    const result = [];
    for (let i = 0; i < count; i++) {
      result.push(chartColors[i % chartColors.length]);
    }
    return result;
  };
  const getColor = (colorName) => {
    return colors[colorName] || colors.primary;
  };
  return {
    colors: {
      primary: colors.button.background,
      secondary: colors.buttonGreen.background,
      tertiary: colors.tertiary,
      error: colors.error,
      warning: colors.warning,
      info: colors.info,
      success: colors.success,
      // Chart-specific colors
      palette: chartColors,
      // Text colors for labels
      text: colors.text,
      // Grid and border colors
      grid: colors.border,
      border: colors.border
    },
    getChartColors,
    getColor,
    brand: name
  };
}
export {
  useChartColors as u
};
