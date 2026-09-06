import { Controller } from '@hotwired/stimulus';

/*
 * THE DEPARTMENT SURFACES' PROGRESSIVE ENHANCEMENT — and nothing that writes.
 *
 * Three roots wear this controller, each a self-contained instance:
 *
 *   · THE CREATE CARD. The segmented "just an area / org-wide" choice flips a
 *     hidden `scope` field (the thing the POST actually carries), lights the
 *     chosen segment, and swaps the data-scope attribute the stylesheet reads to
 *     show or hide the area picker. Without JavaScript the field defaults to
 *     "area", the picker is visible, and the form still posts a real scope.
 *
 *   · A REGISTER ROW. Rename and Change-scope reveal the row's write panel — the
 *     forms and the filed positions — which the server renders present but
 *     `hidden`, so the forms exist for a no-JS post and are only folded away
 *     until asked for.
 *
 *   · THE LENS. The tab strip switches panels in place. Each tab is a real
 *     <button> and each panel is server-rendered; this only toggles which is
 *     shown, so the identity card on the Overview panel appears on Overview alone
 *     exactly because it lives in that panel and no other.
 *
 * IT WRITES NOTHING. Every save on these surfaces is a form the server owns;
 * there is no fetch in this file on purpose, the same rule the permission-group
 * controller keeps.
 */
export default class extends Controller {
    static targets = ['scopeField', 'hint', 'panel', 'segment', 'tab', 'tabpanel'];
    static values = {
        hintArea: String,
        hintOrg: String,
    };

    /*
     * PROGRESSIVE ENHANCEMENT, the module's standing rule: the server renders
     * every panel OPEN, so a host without this controller can still rename, change
     * a scope, move a position and read every tab. connect() is what folds them —
     * a register row's write panel collapses, and the lens shows the Overview tab
     * alone — so the tidy state exists exactly when there is JavaScript to undo it.
     */
    connect() {
        if (this.hasPanelTarget) {
            this.panelTarget.hidden = true;
        }
        if (this.hasTabpanelTargets) {
            this.tabpanelTargets.forEach((p) => {
                p.hidden = p.getAttribute('data-tab-panel') !== 'overview';
            });
        }
    }

    /* CREATE CARD — flip the scope the form will post. */
    setScope(event) {
        const mode = event.currentTarget.getAttribute('data-scope-set');
        this.element.setAttribute('data-scope', mode);

        if (this.hasScopeFieldTarget) {
            this.scopeFieldTarget.value = mode;
        }
        this.segmentTargets.forEach((b) => b.classList.toggle('on', b.getAttribute('data-scope-set') === mode));

        if (this.hasHintTarget) {
            const text = mode === 'org' ? this.hintOrgValue : this.hintAreaValue;
            if (text) {
                this.hintTarget.innerHTML = text;
            }
        }
    }

    /* REGISTER ROW — reveal (or fold away) the write panel. */
    toggle() {
        if (this.hasPanelTarget) {
            this.panelTarget.hidden = !this.panelTarget.hidden;
        }
    }

    /* LENS — switch the shown tab panel. */
    tab(event) {
        const name = event.currentTarget.getAttribute('data-tab');
        this.tabTargets.forEach((t) => t.classList.toggle('on', t.getAttribute('data-tab') === name));
        this.tabpanelTargets.forEach((p) => {
            p.hidden = p.getAttribute('data-tab-panel') !== name;
        });
    }
}
