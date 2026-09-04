import { Controller } from '@hotwired/stimulus';

/*
 * ONE UMBRELLA'S WHOLE GROUP, TICKED OR UNTICKED AT ONCE.
 *
 * The scope is a `.pm-group` — one module's permissions, bounded — and the
 * targets are the heading's button and the real checkboxes in the rows beneath
 * it. Nothing else on the matrix is this controller's business.
 *
 * IT IS AN ENHANCEMENT, AND THE MARKUP MAKES THAT TRUE RATHER THAN CLAIMED. The
 * server renders the button DISABLED. Enabling it is the first thing connect()
 * does, so the button is live exactly when there is something behind it and
 * reads as the plain count it has always been when there is not. A control that
 * looks operable and is not is worse than a control that is not there.
 *
 * IT WRITES NOTHING. Ticking a box here is the same event as ticking it by
 * hand: a change to the form, and no more. Nothing reaches the database until
 * Save is pressed, which is the sentence the save bar carries and the reason
 * this matrix has no surprises in it. There is no fetch in this file on purpose.
 *
 * THE VISUAL FOLLOWS THE CHECKBOX, NOT THIS CODE. `.pm-row:has(> .pm-check:
 * checked) .pm-tick` is already in the stylesheet, so setting `.checked` is the
 * whole of the update — a controller that also toggled a class would be a second
 * opinion about what "ticked" means, and the two would eventually disagree.
 */
export default class extends Controller {
    static targets = ['all', 'box'];

    connect() {
        if (this.hasAllTarget) {
            this.allTarget.disabled = false;
        }

        this.count();
    }

    /*
     * GRANT THE WHOLE GROUP UNLESS IT IS ALREADY WHOLLY GRANTED, in which case
     * take it back. A partly-ticked group fills up rather than emptying: the
     * button is read as "all", and the surprising direction from half-on is the
     * one that throws work away.
     */
    toggle() {
        const grant = this.boxTargets.some((box) => !box.checked);

        this.boxTargets.forEach((box) => {
            if (box.checked !== grant) {
                box.checked = grant;
                // So anything else watching the form sees a real change, the
                // same as a click on the row would have produced.
                box.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        this.count();
    }

    /* The count says what is held right now, or the button lies after one press. */
    count() {
        if (!this.hasAllTarget) {
            return;
        }

        const readout = this.allTarget.querySelector('.c');
        if (readout) {
            readout.textContent = `${this.boxTargets.filter((box) => box.checked).length} of ${this.boxTargets.length}`;
        }
    }
}
