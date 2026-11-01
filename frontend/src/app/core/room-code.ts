/**
 * A room's code as the schedules, room lists and labels show it: without the sample project's building prefix `H1/`
 * (`H1/B_04` → `B_04`). Other codes are shown as they are.
 */
export const roomCodeText = (code: string): string => (code.startsWith('H1/') ? code.slice(3) : code);
