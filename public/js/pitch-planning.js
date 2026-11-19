function clubunioPitchPlanner() {
    return {
        dragKey: null,
        busy: false,
        selection: null,
        suppressClick: false,
        timeLabel(minute) {
            return `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
        },
        startRange(event, field, date) {
            const slot = event.target.closest('[data-pitch-minute]');
            if (event.pointerType === 'touch') {
                this.suppressClick = false;
                return;
            }
            if (event.button !== 0 || !slot || this.busy || this.dragKey) return;
            const minute = Number(slot.dataset.pitchMinute);
            this.selection = { field, date, anchor: minute, end: minute, pointer: event.pointerId, originY: event.clientY, moved: false };
            this.suppressClick = true;
            event.currentTarget.setPointerCapture(event.pointerId);
            event.preventDefault();
        },
        extendRange(event) {
            if (!this.selection || this.selection.pointer !== event.pointerId) return;
            const y = event.clientY - event.currentTarget.getBoundingClientRect().top;
            this.selection.end = Math.max(0, Math.min(47, Math.floor(y / 45))) * 30;
            this.selection.moved ||= Math.abs(event.clientY - this.selection.originY) > 4;
        },
        finishRange(event) {
            if (!this.selection || this.selection.pointer !== event.pointerId) return;
            this.extendRange(event);
            const selected = this.selection;
            this.selection = null;
            if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
            const start = Math.min(selected.anchor, selected.end);
            const duration = selected.moved ? Math.abs(selected.end - selected.anchor) + 30 : 120;
            this.requestRange(selected.field, selected.date, start, duration);
        },
        cancelRange() {
            this.selection = null;
        },
        activateSlot(event, field, date, minute) {
            if (event.detail === 0 || !this.suppressClick) return this.requestRange(field, date, minute);
        },
        requestRange(field, date, minute, duration = 120) {
            if (this.busy) return;
            this.busy = true;
            return this.$wire.requestSlot(field, date, this.timeLabel(minute), duration)
                .finally(() => { this.busy = false; });
        },
        rangeStyle(field) {
            if (!this.selection || this.selection.field !== field) return { display: 'none' };
            return {
                top: `${Math.min(this.selection.anchor, this.selection.end) * 1.5}px`,
                height: `${(Math.abs(this.selection.end - this.selection.anchor) + 30) * 1.5}px`,
            };
        },
        rangeText(template) {
            if (!this.selection) return '';
            return template.replace(':start', this.timeLabel(Math.min(this.selection.anchor, this.selection.end)))
                .replace(':end', this.timeLabel(Math.max(this.selection.anchor, this.selection.end) + 30))
                .replace(':minutes', Math.abs(this.selection.end - this.selection.anchor) + 30);
        },
    };
}
