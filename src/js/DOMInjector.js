/**
 * Injecte le widget Gaitcha dans le formulaire.
 *
 * Genere un widget stylise autonome avec :
 * - Checkbox custom avec animation (idle -> loading -> checked)
 * - Label configurable
 * - Badge "gaitcha" avec shield SVG
 * - Input hidden pour le token
 * - Input hidden pour le log comportemental
 * - Dark mode (force ou auto via prefers-color-scheme)
 * - Ripple au clic
 */

/** @type {boolean} */
var cssInjected = false;

/**
 * SVG checkmark path (stroke-based).
 *
 * @type {string}
 */
var CHECKMARK_PATH = 'M1.5 5L5 8.5L11.5 1.5';

/**
 * SVG shield path (stroke-based).
 *
 * @type {string}
 */
var SHIELD_PATH = 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z';

/**
 * Loading delay range (ms).
 *
 * @type {number}
 */
var LOADING_MIN_MS = 300;

/**
 * @type {number}
 */
var LOADING_MAX_MS = 800;

/**
 * Widget CSS.
 *
 * Injected once via a <style> tag in <head>.
 * Uses CSS custom properties scoped to .gaitcha-widget for theming + dark mode.
 *
 * @type {string}
 */
var WIDGET_CSS = [
    /* ── Variables (scoped to widget) ── */
    '.gaitcha-widget {',
    '  --g-bg: #ffffff;',
    '  --g-checkbox-bg: #ffffff;',
    '  --g-border: #e2e6ea;',
    '  --g-border-hover: #c8cdd3;',
    '  --g-accent: #3d7df6;',
    '  --g-accent-light: #eef3ff;',
    '  --g-text: #1a1d23;',
    '  --g-sub: #8a9099;',
    '  --g-success: #22c55e;',
    '  --g-success-light: #f0fdf4;',
    '  --g-success-border: #d1fae5;',
    '  --g-success-text: #15803d;',
    '  --g-shadow: 0 1px 3px rgba(0,0,0,0.07), 0 4px 12px rgba(0,0,0,0.05);',
    '  --g-shadow-hover: 0 2px 6px rgba(0,0,0,0.09), 0 8px 20px rgba(0,0,0,0.07);',
    '  --g-radius: 10px;',
    '  --g-transition: 200ms cubic-bezier(0.4, 0, 0.2, 1);',
    '',
    '  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;',
    '  background: var(--g-bg) !important;',
    '  border: 1.5px solid var(--g-border) !important;',
    '  border-radius: var(--g-radius) !important;',
    '  box-shadow: var(--g-shadow) !important;',
    '  padding: 14px 16px 12px !important;',
    '  width: 280px !important;',
    '  display: flex !important;',
    '  align-items: center !important;',
    '  justify-content: space-between !important;',
    '  gap: 12px !important;',
    '  transition: border-color var(--g-transition), box-shadow var(--g-transition), background var(--g-transition);',
    '  cursor: pointer;',
    '  user-select: none;',
    '  -webkit-user-select: none;',
    '  -webkit-tap-highlight-color: transparent;',
    '  position: relative !important;',
    '  overflow: hidden !important;',
    '  box-sizing: border-box !important;',
    '  line-height: 1 !important;',
    '}',
    '',

    /* ── Hover ── */
    '.gaitcha-widget:hover {',
    '  border-color: var(--g-border-hover) !important;',
    '  box-shadow: var(--g-shadow-hover) !important;',
    '}',
    '',
    '.gaitcha-widget:hover .gaitcha-widget__checkbox {',
    '  border-color: var(--g-accent) !important;',
    '  box-shadow: 0 0 0 3px var(--g-accent-light) !important;',
    '}',
    '',
    '.gaitcha-widget:hover .gaitcha-widget__shield {',
    '  opacity: 0.55 !important;',
    '}',
    '',

    /* ── Focus ── */
    '.gaitcha-widget:focus-visible {',
    '  outline: 2px solid var(--g-accent);',
    '  outline-offset: 2px;',
    '}',
    '',

    /* ── Loading state ── */
    '.gaitcha-widget--loading .gaitcha-widget__checkbox {',
    '  border-color: var(--g-accent) !important;',
    '}',
    '',

    /* ── Checked state (after hover so it wins in cascade) ── */
    '.gaitcha-widget--checked {',
    '  border-color: var(--g-success-border) !important;',
    '  background: var(--g-success-light) !important;',
    '  cursor: default;',
    '}',
    '',
    '.gaitcha-widget--checked .gaitcha-widget__checkbox {',
    '  background: var(--g-success) !important;',
    '  border-color: var(--g-success) !important;',
    '  box-shadow: 0 0 0 3px rgba(34,197,94,0.15) !important;',
    '}',
    '',
    '.gaitcha-widget--checked .gaitcha-widget__checkmark {',
    '  opacity: 1 !important;',
    '  transform: scale(1) !important;',
    '}',
    '',
    '.gaitcha-widget--checked .gaitcha-widget__label {',
    '  color: var(--g-success-text) !important;',
    '}',
    '',
    '.gaitcha-widget--checked .gaitcha-widget__shield {',
    '  opacity: 0.55 !important;',
    '  color: var(--g-success) !important;',
    '}',
    '',

    /* ── Error state ── */
    '.gaitcha-widget--error {',
    '  border-color: #f44336 !important;',
    '}',
    '',
    '.gaitcha-widget--error .gaitcha-widget__checkbox {',
    '  border-color: #f44336 !important;',
    '}',
    '',

    /* ── Hidden real checkbox (important to survive third-party CSS) ── */
    '.gaitcha-widget__input {',
    '  position: absolute !important;',
    '  opacity: 0 !important;',
    '  width: 0 !important;',
    '  height: 0 !important;',
    '  margin: 0 !important;',
    '  padding: 0 !important;',
    '  border: 0 !important;',
    '  pointer-events: none !important;',
    '  overflow: hidden !important;',
    '  clip: rect(0,0,0,0) !important;',
    '}',
    '',

    /* ── Left section (checkbox visual + label) ── */
    '.gaitcha-widget__left {',
    '  display: flex !important;',
    '  align-items: center !important;',
    '  gap: 11px !important;',
    '  flex: 1 !important;',
    '  min-width: 0 !important;',
    '}',
    '',

    /* ── Custom checkbox ── */
    '.gaitcha-widget__checkbox {',
    '  width: 22px !important;',
    '  height: 22px !important;',
    '  min-width: 22px !important;',
    '  border-radius: 5px !important;',
    '  border: 1.5px solid var(--g-border) !important;',
    '  background: var(--g-checkbox-bg) !important;',
    '  position: relative !important;',
    '  transition: border-color var(--g-transition), background var(--g-transition), box-shadow var(--g-transition);',
    '  display: flex !important;',
    '  align-items: center !important;',
    '  justify-content: center !important;',
    '}',
    '',

    /* ── Spinner ── */
    '.gaitcha-widget__spinner {',
    '  width: 13px !important;',
    '  height: 13px !important;',
    '  border: 2px solid transparent !important;',
    '  border-top-color: var(--g-accent) !important;',
    '  border-radius: 50% !important;',
    '  position: absolute !important;',
    '  opacity: 0;',
    '  transition: opacity 150ms;',
    '  box-sizing: border-box !important;',
    '}',
    '',
    '.gaitcha-widget--loading .gaitcha-widget__spinner {',
    '  opacity: 1 !important;',
    '  animation: gaitcha-spin 0.7s linear infinite !important;',
    '}',
    '',

    /* ── Checkmark SVG ── */
    '.gaitcha-widget__checkmark {',
    '  position: absolute !important;',
    '  opacity: 0;',
    '  transform: scale(0.5);',
    '  transition: opacity 200ms, transform 250ms cubic-bezier(0.34, 1.56, 0.64, 1);',
    '}',
    '',

    /* ── Label ── */
    '.gaitcha-widget__label {',
    '  font-size: 14px !important;',
    '  font-weight: 500 !important;',
    '  color: var(--g-text) !important;',
    '  line-height: 1.3 !important;',
    '  white-space: nowrap !important;',
    '  transition: color var(--g-transition);',
    '}',
    '',

    /* ── Badge (right side) ── */
    '.gaitcha-widget__badge {',
    '  display: flex !important;',
    '  flex-direction: column !important;',
    '  align-items: center !important;',
    '  gap: 3px !important;',
    '  flex-shrink: 0 !important;',
    '}',
    '',
    '.gaitcha-widget__shield {',
    '  width: 20px !important;',
    '  height: 20px !important;',
    '  opacity: 0.35;',
    '  transition: opacity var(--g-transition), color var(--g-transition);',
    '  color: var(--g-sub);',
    '}',
    '',
    '.gaitcha-widget__brand {',
    '  font-family: ui-monospace, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace !important;',
    '  font-size: 9px !important;',
    '  letter-spacing: 0.04em !important;',
    '  color: var(--g-sub) !important;',
    '  line-height: 1 !important;',
    '  opacity: 0.7;',
    '  text-transform: lowercase;',
    '}',
    '',

    /* ── Ripple ── */
    '.gaitcha-widget__ripple {',
    '  position: absolute !important;',
    '  border-radius: 50% !important;',
    '  background: rgba(61, 125, 246, 0.12) !important;',
    '  transform: scale(0);',
    '  animation: gaitcha-ripple 500ms ease-out forwards;',
    '  pointer-events: none !important;',
    '}',
    '',

    /* ── Keyframes ── */
    '@keyframes gaitcha-spin {',
    '  to { transform: rotate(360deg); }',
    '}',
    '',
    '@keyframes gaitcha-ripple {',
    '  to { transform: scale(4); opacity: 0; }',
    '}',
    '',

    /* ── Dark mode (forced) ── */
    '.gaitcha-widget--dark {',
    '  --g-bg: #1e2128;',
    '  --g-checkbox-bg: #252830;',
    '  --g-border: #2d3139;',
    '  --g-border-hover: #3d4553;',
    '  --g-text: #e8eaf0;',
    '  --g-sub: #5a6072;',
    '  --g-accent-light: rgba(61,125,246,0.1);',
    '  --g-success-light: rgba(34,197,94,0.07);',
    '  --g-success-border: rgba(34,197,94,0.3);',
    '  --g-success-text: #4ade80;',
    '}',
    '',
    /* ── Dark mode (auto, follows OS) ── */
    '@media (prefers-color-scheme: dark) {',
    '  .gaitcha-widget--auto {',
    '    --g-bg: #1e2128;',
    '    --g-checkbox-bg: #252830;',
    '    --g-border: #2d3139;',
    '    --g-border-hover: #3d4553;',
    '    --g-text: #e8eaf0;',
    '    --g-sub: #5a6072;',
    '    --g-accent-light: rgba(61,125,246,0.1);',
    '    --g-success-light: rgba(34,197,94,0.07);',
    '    --g-success-border: rgba(34,197,94,0.3);',
    '    --g-success-text: #4ade80;',
    '  }',
    '}',
].join('\n');

/**
 * Injecte le CSS du widget une seule fois dans le <head>.
 */
function injectWidgetCSS() {
    if (cssInjected) {
        return;
    }
    cssInjected = true;

    var style = document.createElement('style');
    style.setAttribute('data-gaitcha', '');
    style.textContent = WIDGET_CSS;
    document.head.appendChild(style);
}

/**
 * Cree le SVG checkmark (stroke-based, blanc sur fond vert).
 *
 * @return {SVGElement} Element SVG checkmark.
 */
function createCheckmarkSVG() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'gaitcha-widget__checkmark');
    svg.setAttribute('width', '13');
    svg.setAttribute('height', '10');
    svg.setAttribute('viewBox', '0 0 13 10');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('aria-hidden', 'true');

    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', CHECKMARK_PATH);
    path.setAttribute('stroke', 'white');
    path.setAttribute('stroke-width', '2.2');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(path);

    return svg;
}

/**
 * Cree le SVG shield (stroke-based, couleur heritee via currentColor).
 *
 * @return {SVGElement} Element SVG shield.
 */
function createShieldSVG() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'gaitcha-widget__shield');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');

    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', SHIELD_PATH);
    svg.appendChild(path);

    return svg;
}

/**
 * Cree un injecteur DOM pour un formulaire.
 *
 * Le widget gere 3 etats visuels : idle -> loading (spinner) -> checked (checkmark).
 * Le clic/clavier sur le widget declenche la transition. Une fois checked,
 * toute interaction est bloquee.
 *
 * @param {HTMLFormElement}  form            Formulaire cible.
 * @param {HTMLElement|null} targetContainer Conteneur cible optionnel pour l'injection.
 * @param {string}          theme           Theme du widget : 'light' (defaut), 'dark', ou 'auto'.
 * @return {object} Injecteur avec inject(), update(), reset(), getCheckbox(), getLogInput(), onCheck(), destroy().
 */
function createDOMInjector(form, targetContainer, theme) {
    /** @type {HTMLDivElement|null} */
    var widget = null;

    /** @type {HTMLInputElement|null} */
    var checkbox = null;

    /** @type {HTMLInputElement|null} */
    var tokenInput = null;

    /** @type {HTMLInputElement|null} */
    var logInput = null;

    /** @type {string} idle | loading | checked */
    var state = 'idle';

    /** @type {Array<function>} */
    var checkCallbacks = [];

    /** @type {number|null} */
    var loadingTimer = null;

    /**
     * Injecte le widget dans le formulaire.
     *
     * @param {string} fieldName      Nom du champ aleatoire.
     * @param {string} token          Token HMAC signe.
     * @param {string} tokenFieldName Nom du champ hidden pour le token.
     * @param {string} label          Label de la checkbox.
     */
    function inject(fieldName, token, tokenFieldName, label) {
        injectWidgetCSS();

        var labelText = label || 'Je ne suis pas un robot';

        // Widget container.
        widget = document.createElement('div');
        widget.className = 'gaitcha-widget';

        // Theme : 'dark' force le dark, 'auto' suit l'OS, 'light' (defaut) = pas de classe.
        if (theme === 'dark') {
            widget.classList.add('gaitcha-widget--dark');
        } else if (theme === 'auto') {
            widget.classList.add('gaitcha-widget--auto');
        }

        widget.setAttribute('role', 'checkbox');
        widget.setAttribute('aria-checked', 'false');
        widget.setAttribute('aria-label', labelText);
        widget.setAttribute('tabindex', '0');

        // Real checkbox (hidden, for form submission only).
        checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = fieldName;
        checkbox.id = 'gaitcha-' + fieldName;
        checkbox.className = 'gaitcha-widget__input';
        checkbox.required = true;
        checkbox.setAttribute('tabindex', '-1');
        checkbox.setAttribute('aria-hidden', 'true');

        // Left section: checkbox visual + label.
        var left = document.createElement('div');
        left.className = 'gaitcha-widget__left';

        var checkboxVisual = document.createElement('div');
        checkboxVisual.className = 'gaitcha-widget__checkbox';

        var spinner = document.createElement('div');
        spinner.className = 'gaitcha-widget__spinner';

        checkboxVisual.appendChild(spinner);
        checkboxVisual.appendChild(createCheckmarkSVG());

        var labelSpan = document.createElement('span');
        labelSpan.className = 'gaitcha-widget__label';
        labelSpan.textContent = labelText;

        left.appendChild(checkboxVisual);
        left.appendChild(labelSpan);

        // Badge (right side).
        var badge = document.createElement('div');
        badge.className = 'gaitcha-widget__badge';
        badge.appendChild(createShieldSVG());

        var brandSpan = document.createElement('span');
        brandSpan.className = 'gaitcha-widget__brand';
        brandSpan.textContent = 'gaitcha';
        badge.appendChild(brandSpan);

        // Assemble widget.
        widget.appendChild(checkbox);
        widget.appendChild(left);
        widget.appendChild(badge);

        // Hidden : token signe.
        tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = tokenFieldName;
        tokenInput.value = token;

        // Hidden : log comportemental.
        logInput = document.createElement('input');
        logInput.type = 'hidden';
        logInput.name = fieldName + '_log';
        logInput.value = '';

        // Event handlers.
        widget.addEventListener('click', handleWidgetClick);
        widget.addEventListener('keydown', handleWidgetKeydown);

        // Injecter dans le conteneur cible, ou avant le bouton submit, ou a la fin du form.
        if (targetContainer) {
            targetContainer.appendChild(widget);
            targetContainer.appendChild(tokenInput);
            targetContainer.appendChild(logInput);
        } else {
            var submitBtn = form.querySelector('[type="submit"], button:not([type])');
            if (submitBtn) {
                form.insertBefore(widget, submitBtn);
                form.insertBefore(tokenInput, submitBtn);
                form.insertBefore(logInput, submitBtn);
            } else {
                form.appendChild(widget);
                form.appendChild(tokenInput);
                form.appendChild(logInput);
            }
        }
    }

    /**
     * Handler de clic sur le widget.
     *
     * @param {MouseEvent} event Evenement click.
     */
    function handleWidgetClick(event) {
        if (event.isTrusted === false) {
            return;
        }
        if (state !== 'idle') {
            return;
        }

        triggerRipple(event);
        startLoading(event);
    }

    /**
     * Handler clavier : espace ou entree active le widget.
     *
     * @param {KeyboardEvent} event Evenement keydown.
     */
    function handleWidgetKeydown(event) {
        if (event.isTrusted === false) {
            return;
        }
        if (event.key !== ' ' && event.key !== 'Enter') {
            return;
        }
        if (state !== 'idle') {
            return;
        }

        event.preventDefault();
        startLoading(event);
    }

    /**
     * Cree un effet ripple au point de clic.
     *
     * @param {MouseEvent} event Evenement click avec coordonnees.
     */
    function triggerRipple(event) {
        if (!widget) {
            return;
        }

        var rect = widget.getBoundingClientRect();
        var ripple = document.createElement('div');
        ripple.className = 'gaitcha-widget__ripple';

        var size = Math.max(rect.width, rect.height);
        var x = (event.clientX - rect.left) - size / 2;
        var y = (event.clientY - rect.top) - size / 2;

        ripple.style.width = size + 'px';
        ripple.style.height = size + 'px';
        ripple.style.left = x + 'px';
        ripple.style.top = y + 'px';

        widget.appendChild(ripple);

        /**
         * Supprime le ripple apres la fin de l'animation.
         */
        function handleRippleEnd() {
            ripple.removeEventListener('animationend', handleRippleEnd);
            if (ripple.parentNode) {
                ripple.parentNode.removeChild(ripple);
            }
        }

        ripple.addEventListener('animationend', handleRippleEnd);
    }

    /**
     * Demarre la phase loading (spinner) avant de cocher.
     *
     * @param {Event} originalEvent Evenement utilisateur d'origine (trusted).
     */
    function startLoading(originalEvent) {
        state = 'loading';
        widget.classList.add('gaitcha-widget--loading');

        var delay = LOADING_MIN_MS + Math.random() * (LOADING_MAX_MS - LOADING_MIN_MS);

        loadingTimer = setTimeout(function onLoadingComplete() {
            completeCheck(originalEvent);
        }, delay);
    }

    /**
     * Finalise le check : coche le checkbox reel, met a jour l'UI, fire les callbacks.
     *
     * @param {Event} originalEvent Evenement utilisateur d'origine.
     */
    function completeCheck(originalEvent) {
        state = 'checked';
        widget.classList.remove('gaitcha-widget--loading');
        widget.classList.add('gaitcha-widget--checked');
        widget.setAttribute('aria-checked', 'true');

        // Cocher le vrai checkbox pour la soumission du formulaire.
        if (checkbox) {
            checkbox.checked = true;
        }

        // Notifier les observers (GaitchaForm enregistre le log ici).
        for (var i = 0; i < checkCallbacks.length; i++) {
            checkCallbacks[i](originalEvent);
        }
    }

    /**
     * Enregistre un callback appele quand le widget passe en etat checked.
     *
     * @param {function} callback Fonction appelee avec l'evenement d'origine.
     */
    function onCheck(callback) {
        checkCallbacks.push(callback);
    }

    /**
     * Remet le widget en etat idle (decoche, pret pour un nouveau cycle).
     *
     * Utilise apres un rejet serveur pour permettre a l'utilisateur de reessayer.
     */
    function reset() {
        if (loadingTimer) {
            clearTimeout(loadingTimer);
            loadingTimer = null;
        }

        state = 'idle';

        if (widget) {
            widget.classList.remove('gaitcha-widget--loading');
            widget.classList.remove('gaitcha-widget--checked');
            widget.setAttribute('aria-checked', 'false');

            // Supprimer les ripples residuels.
            var ripples = widget.querySelectorAll('.gaitcha-widget__ripple');
            for (var i = 0; i < ripples.length; i++) {
                ripples[i].parentNode.removeChild(ripples[i]);
            }
        }

        if (checkbox) {
            checkbox.checked = false;
        }
    }

    /**
     * Met a jour les champs apres un refresh de token.
     *
     * @param {string} newFieldName      Nouveau nom de champ.
     * @param {string} newToken          Nouveau token.
     * @param {string} newTokenFieldName Nouveau nom de champ token.
     */
    function update(newFieldName, newToken, newTokenFieldName) {
        if (checkbox) {
            checkbox.name = newFieldName;
            checkbox.id = 'gaitcha-' + newFieldName;
        }

        if (tokenInput) {
            tokenInput.name = newTokenFieldName;
            tokenInput.value = newToken;
        }

        if (logInput) {
            logInput.name = newFieldName + '_log';
        }
    }

    /**
     * @return {HTMLInputElement|null} La checkbox Gaitcha.
     */
    function getCheckbox() {
        return checkbox;
    }

    /**
     * @return {HTMLInputElement|null} L'input hidden du log.
     */
    function getLogInput() {
        return logInput;
    }

    /**
     * Supprime les champs injectes et nettoie les ressources.
     */
    function destroy() {
        if (loadingTimer) {
            clearTimeout(loadingTimer);
            loadingTimer = null;
        }
        if (widget) {
            widget.removeEventListener('click', handleWidgetClick);
            widget.removeEventListener('keydown', handleWidgetKeydown);
            if (widget.parentNode) {
                widget.parentNode.removeChild(widget);
            }
        }
        if (tokenInput && tokenInput.parentNode) {
            tokenInput.parentNode.removeChild(tokenInput);
        }
        if (logInput && logInput.parentNode) {
            logInput.parentNode.removeChild(logInput);
        }
        widget = null;
        checkbox = null;
        tokenInput = null;
        logInput = null;
        checkCallbacks = [];
        state = 'idle';
    }

    return {
        inject: inject,
        update: update,
        reset: reset,
        getCheckbox: getCheckbox,
        getLogInput: getLogInput,
        onCheck: onCheck,
        destroy: destroy,
    };
}

export { createDOMInjector };
