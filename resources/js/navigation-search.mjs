export function menuMatches(items, query) {
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    const words = normalize(query.trim()).split(/\s+/).filter(Boolean);
    return items.filter(item => words.every(word => normalize(`${item.label} ${item.group}`).includes(word)));
}
