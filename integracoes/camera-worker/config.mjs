export function boundedInteger(rawValue, fallback, minimum, maximum) {
  const value = Number.parseInt(rawValue || "", 10);
  return Number.isInteger(value) && value >= minimum && value <= maximum ? value : fallback;
}
