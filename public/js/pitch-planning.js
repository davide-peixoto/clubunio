function clubunioPitchPlanner() {
    return {
        dragKey: null,
        busy: false,
        selection: null,
        suppressClick: false,
        scrollFrame: null,
        scrollColumn: null,
        pointerY: null,
        scrollTime: null,
        timeLabel(minute) {
            return `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
        },
        startRange(event, field, date) {
            const slot = event.target.closest('[data-pitch-minute]');
            if (event.pointerType === 'touch') {
                this.suppressClick = false;
                return;
            }
            if (event.button !== 0 || !slot || this.busy || this.dragKey || this.selection) return;
            const minute = Number(slot.dataset.pitchMinute);
            this.selection = { field, date, anchor: minute, end: minute, pointer: event.pointerId, originY: event.clientY, moved: false };
            this.suppressClick = true;
            this.scrollColumn = event.currentTarget;
            this.pointerY = event.clientY;
            this.scrollTime = null;
            this.scrollFrame = requestAnimationFrame(time => this.scrollSelection(time));
            event.currentTarget.setPointerCapture(event.pointerId);
            event.preventDefault();
        },
        extendRange(event) {
            if (!this.selection || this.selection.pointer !== event.pointerId) return;
            this.pointerY = event.clientY;
            this.updateRangeEnd();
        },
        updateRangeEnd() {
            if (!this.selection || !this.scrollColumn) return;
            const y = this.pointerY - this.scrollColumn.getBoundingClientRect().top;
            this.selection.end = Math.max(0, Math.min(47, Math.floor(y / 45))) * 30;
            this.selection.moved ||= this.selection.end !== this.selection.anchor || Math.abs(this.pointerY - this.selection.originY) > 4;
        },
        scrollSelection(time) {
            this.scrollFrame = null;
            if (!this.selection || !this.scrollColumn?.isConnected) return this.stopEdgeScroll();
            const viewport = this.scrollColumn.closest('.pitch-scroll');
            if (!viewport) return this.stopEdgeScroll();
            const bounds = viewport.getBoundingClientRect();
            const heading = viewport.querySelector('.pitch-heading')?.getBoundingClientRect().height || 0;
            const top = bounds.top + heading;
            const bottom = bounds.top + viewport.clientHeight;
            const edge = Math.min(48, (bottom - top) / 3);
            if (edge <= 0) return this.cancelRange();
            const strength = this.pointerY < top + edge
                ? Math.max(-1, Math.min(0, (this.pointerY - top - edge) / edge))
                : Math.max(0, Math.min(1, (this.pointerY - bottom + edge) / edge));
            const seconds = this.scrollTime === null ? 0 : Math.max(0, Math.min(50, time - this.scrollTime)) / 1000;
            this.scrollTime = time;
            const distance = this.selection.moved ? strength * 480 * seconds : 0;
            const next = Math.max(0, Math.min(viewport.scrollHeight - viewport.clientHeight, viewport.scrollTop + distance));
            if (next !== viewport.scrollTop) {
                viewport.scrollTop = next;
                this.updateRangeEnd();
            }
            this.scrollFrame = requestAnimationFrame(nextTime => this.scrollSelection(nextTime));
        },
        stopEdgeScroll() {
            if (this.scrollFrame !== null) cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
            this.scrollColumn = null;
            this.pointerY = null;
            this.scrollTime = null;
        },
        destroy() {
            this.stopEdgeScroll();
        },
        finishRange(event) {
            if (!this.selection || this.selection.pointer !== event.pointerId) return;
            this.extendRange(event);
            const selected = this.selection;
            this.selection = null;
            this.stopEdgeScroll();
            if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
            const start = Math.min(selected.anchor, selected.end);
            const duration = selected.moved ? Math.abs(selected.end - selected.anchor) + 30 : 120;
            this.requestRange(selected.field, selected.date, start, duration);
        },
        cancelRange() {
            this.selection = null;
            this.stopEdgeScroll();
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
