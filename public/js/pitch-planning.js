function clubunioPitchPlanner() {
    return {
        dragKey: null,
        busy: false,
        selection: null,
        suppressClick: false,
        scrollFrame: null,
        scrollColumn: null,
        pointerY: null,
        pointerX: null,
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
            this.startEdgeScroll(event.currentTarget, event.clientY);
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
        startMove(event, key) {
            const column = event.currentTarget.closest('.pitch-column');
            if (this.busy || !event.currentTarget.draggable || !column) {
                event.preventDefault();
                return;
            }
            this.cancelRange();
            this.dragKey = key;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', key);
            this.startEdgeScroll(column, event.clientY, event.clientX);
        },
        updateMove(event) {
            if (!this.dragKey || this.busy) return;
            const column = event.target.closest('.pitch-column');
            if (column) this.scrollColumn = column;
            this.pointerY = event.clientY;
            this.pointerX = event.clientX;
        },
        dropMove(event, date) {
            if (!this.dragKey || this.busy) return;
            const viewport = event.currentTarget.closest('.pitch-scroll');
            if (!viewport) return this.cancelRange();
            const bounds = viewport.getBoundingClientRect();
            const axis = viewport.querySelector('.pitch-time-heading')?.getBoundingClientRect().width || 0;
            const heading = viewport.querySelector('.pitch-heading')?.getBoundingClientRect().height || 0;
            if (event.clientX < bounds.left + axis || event.clientX >= bounds.left + viewport.clientWidth ||
                event.clientY < bounds.top + heading || event.clientY >= bounds.top + viewport.clientHeight) return this.cancelRange();
            const target = [...viewport.querySelectorAll('.pitch-column')].find(column => {
                const rect = column.getBoundingClientRect();
                return event.clientX >= rect.left && event.clientX < rect.right;
            });
            if (!target) return this.cancelRange();
            const key = this.dragKey;
            const field = Number(target.dataset.pitchField);
            const y = event.clientY - target.getBoundingClientRect().top;
            const minute = Math.max(0, Math.min(47, Math.floor(y / 45))) * 30;
            this.endMove();
            this.busy = true;
            return this.$wire.proposeMove(key, field, date, this.timeLabel(minute))
                .finally(() => { this.busy = false; });
        },
        endMove() {
            this.dragKey = null;
            this.stopEdgeScroll();
        },
        cancelPointerRange(event) {
            if (this.selection?.pointer === event.pointerId) this.cancelRange();
        },
        startEdgeScroll(column, pointerY, pointerX = null) {
            this.stopEdgeScroll();
            this.scrollColumn = column;
            this.pointerY = pointerY;
            this.pointerX = pointerX;
            this.scrollFrame = requestAnimationFrame(time => this.scrollCalendar(time));
        },
        scrollCalendar(time) {
            this.scrollFrame = null;
            if ((!this.selection && !this.dragKey) || !this.scrollColumn?.isConnected) return this.cancelRange();
            const viewport = this.scrollColumn.closest('.pitch-scroll');
            if (!viewport) return this.stopEdgeScroll();
            const bounds = viewport.getBoundingClientRect();
            const heading = viewport.querySelector('.pitch-heading')?.getBoundingClientRect().height || 0;
            const top = bounds.top + heading;
            const bottom = bounds.top + viewport.clientHeight;
            if (bottom <= top) return this.cancelRange();
            const strength = this.edgeStrength(this.pointerY, top, bottom);
            const seconds = this.scrollTime === null ? 0 : Math.max(0, Math.min(50, time - this.scrollTime)) / 1000;
            this.scrollTime = time;
            const distance = (this.dragKey || this.selection?.moved) ? strength * 480 * seconds : 0;
            const next = Math.max(0, Math.min(viewport.scrollHeight - viewport.clientHeight, viewport.scrollTop + distance));
            if (next !== viewport.scrollTop) {
                viewport.scrollTop = next;
                this.updateRangeEnd();
            }
            if (this.dragKey && this.pointerX !== null && viewport.scrollWidth > viewport.clientWidth) {
                const axis = viewport.querySelector('.pitch-time-heading')?.getBoundingClientRect().width || 0;
                const strengthX = this.edgeStrength(this.pointerX, bounds.left + axis, bounds.left + viewport.clientWidth);
                const nextX = Math.max(0, Math.min(viewport.scrollWidth - viewport.clientWidth, viewport.scrollLeft + strengthX * 480 * seconds));
                if (nextX !== viewport.scrollLeft) viewport.scrollLeft = nextX;
            }
            this.scrollFrame = requestAnimationFrame(nextTime => this.scrollCalendar(nextTime));
        },
        edgeStrength(position, start, end) {
            const edge = Math.min(48, (end - start) / 3);
            if (edge <= 0) return 0;
            return position < start + edge
                ? Math.max(-1, Math.min(0, (position - start - edge) / edge))
                : Math.max(0, Math.min(1, (position - end + edge) / edge));
        },
        stopEdgeScroll() {
            if (this.scrollFrame !== null) cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
            this.scrollColumn = null;
            this.pointerY = null;
            this.pointerX = null;
            this.scrollTime = null;
        },
        destroy() {
            this.cancelRange();
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
            this.endMove();
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
