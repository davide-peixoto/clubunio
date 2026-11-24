export function civilDate(value, offset = 0) {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + offset, 12)).toISOString().slice(0, 10);
}
export function weekDates(value) {
    const weekday = (new Date(`${value}T12:00:00Z`).getUTCDay() + 6) % 7;
    return Array.from({ length: 7 }, (_, i) => civilDate(value, i - weekday));
}
