// assets/js/main.js

// ── Theme toggle (dark/light) ────────────────────────────────
function applyTheme(theme, persist = true) {
    const root = document.documentElement;
    root.setAttribute('data-theme', theme);
    if (persist) {
        try {
            localStorage.setItem('dvd_theme', theme);
        } catch (e) {
            // Ignore storage errors (private mode / blocked storage)
        }
    }

    const toggle = document.getElementById('theme-toggle-checkbox');
    if (!toggle) return;
    toggle.checked = theme === 'dark';
}

(() => {
    let savedTheme = null;
    try {
        savedTheme = localStorage.getItem('dvd_theme');
    } catch (e) {
        savedTheme = null;
    }
    const initialTheme = (savedTheme === 'light' || savedTheme === 'dark') ? savedTheme : 'dark';
    applyTheme(initialTheme, false);

    const toggle = document.getElementById('theme-toggle-checkbox');
    if (toggle) {
        toggle.addEventListener('change', () => {
            applyTheme(toggle.checked ? 'dark' : 'light');
        });
    }
})();

// ── Sidebar compact mode (icons only) ───────────────────────
function applySidebarCompact(compact, persist = true) {
    const root = document.documentElement;
    root.classList.toggle('sidebar-compact', compact);

    if (persist) {
        try {
            localStorage.setItem('dvd_sidebar_compact', compact ? '1' : '0');
        } catch (e) {
            // Ignore storage errors
        }
    }

    const toggle = document.getElementById('sidebar-compact-toggle');
    const thumbIcon = document.querySelector('#compact-pill-thumb .bi');
    const navLinks = document.querySelectorAll('.sidebar .nav-item a');

    navLinks.forEach((link) => {
        if (!link.dataset.titleOriginal && link.hasAttribute('title')) {
            link.dataset.titleOriginal = link.getAttribute('title') || '';
        }

        if (compact) {
            const label = (link.textContent || '').replace(/\s+/g, ' ').trim();
            if (label) {
                link.setAttribute('title', label);
            }
        } else if (link.dataset.titleOriginal) {
            link.setAttribute('title', link.dataset.titleOriginal);
        } else {
            link.removeAttribute('title');
        }
    });

    if (!toggle) return;
    toggle.checked = compact;
    if (thumbIcon) {
        thumbIcon.classList.toggle('bi-layout-sidebar', !compact);
        thumbIcon.classList.toggle('bi-layout-sidebar-inset', compact);
    }
}

(() => {
    let compact = false;
    try {
        compact = localStorage.getItem('dvd_sidebar_compact') === '1';
    } catch (e) {
        compact = document.documentElement.classList.contains('sidebar-compact');
    }

    applySidebarCompact(compact, false);

    const toggle = document.getElementById('sidebar-compact-toggle');
    if (toggle) {
        toggle.addEventListener('change', () => {
            applySidebarCompact(toggle.checked);
        });
    }
})();

// ── Sidebar section collapse ─────────────────────────────────
(() => {
    const nav = document.querySelector('.sidebar nav');
    if (!nav) return;

    const appConfig = window.DVD_APP || {};
    const apiUrl = (typeof appConfig.baseUrl === 'string' && appConfig.baseUrl !== '')
        ? `${appConfig.baseUrl}/api/user_sidebar_sections.php`
        : '/api/user_sidebar_sections.php';
    const csrfToken = typeof appConfig.csrfToken === 'string' ? appConfig.csrfToken : '';

    let savedLocal = {};
    try {
        savedLocal = JSON.parse(localStorage.getItem('dvd_sidebar_sections') || '{}') || {};
    } catch (e) {
        savedLocal = {};
    }

    const savedServer = (appConfig && typeof appConfig.sidebarSections === 'object' && appConfig.sidebarSections)
        ? appConfig.sidebarSections
        : {};

    let saved = (Object.keys(savedServer).length > 0) ? savedServer : savedLocal;
    if (!saved || typeof saved !== 'object') {
        saved = {};
    }

    const isSidebarSectionHeader = (node) => {
        return !!node && (node.classList.contains('nav-section') || node.classList.contains('nav-subsection'));
    };

    const getHeaderLevel = (node) => {
        if (!isSidebarSectionHeader(node)) return 0;
        return node.classList.contains('nav-section') ? 1 : 2;
    };

    const sections = Array.from(nav.querySelectorAll('.nav-section, .nav-subsection'));

    const collectItems = (section) => {
        const items = [];
        const level = getHeaderLevel(section);
        let node = section.nextElementSibling;
        while (node) {
            if (isSidebarSectionHeader(node) && getHeaderLevel(node) <= level) {
                break;
            }

            if (level === 1) {
                if (node.classList.contains('nav-item') || node.classList.contains('nav-subsection')) {
                    items.push(node);
                }
            } else if (node.classList.contains('nav-item')) {
                items.push(node);
            }

            node = node.nextElementSibling;
        }
        return items;
    };

    const collectSubsections = (section) => {
        if (getHeaderLevel(section) !== 1) return [];
        const items = [];
        let node = section.nextElementSibling;
        while (node) {
            if (node.classList.contains('nav-section')) {
                break;
            }
            if (node.classList.contains('nav-subsection')) {
                items.push(node);
            }
            node = node.nextElementSibling;
        }
        return items;
    };

    const getParentSection = (section) => {
        if (getHeaderLevel(section) !== 2) return null;
        let node = section.previousElementSibling;
        while (node) {
            if (node.classList.contains('nav-section')) {
                return node;
            }
            node = node.previousElementSibling;
        }
        return null;
    };
    const persistLocal = () => {
        try {
            localStorage.setItem('dvd_sidebar_sections', JSON.stringify(saved));
        } catch (e) {
            // Ignore storage errors
        }
    };

    const persistServer = () => {
        if (!csrfToken) return;
        fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                _csrf: csrfToken,
                sections: saved,
            }),
        }).catch(() => {
            // Ignore network/server persistence errors
        });
    };

    const applyVisibility = (section, collapsed) => {
        const items = collectItems(section);
        items.forEach((item) => {
            if (collapsed) {
                item.style.setProperty('display', 'none', 'important');
            } else {
                item.style.removeProperty('display');
            }
        });
    };

    sections.forEach((section, index) => {
        const items = collectItems(section);
        if (items.length === 0) return;

        const key = String(index);
        const collapsed = saved[key] === 1;

        section.classList.add('nav-section-collapsible');
        section.classList.toggle('nav-section-collapsed', collapsed);
        section.dataset.sidebarSectionKey = key;
        section.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        section.setAttribute('role', 'button');
        section.setAttribute('tabindex', '0');

        const parentSection = getParentSection(section);
        const parentCollapsed = !!parentSection && parentSection.classList.contains('nav-section-collapsed');
        if (!parentCollapsed) {
            applyVisibility(section, collapsed);
        }
    });

    const activeItem = nav.querySelector('.nav-item a.active');
    if (activeItem) {
        sections.forEach((section) => {
            if (!collectItems(section).some((item) => item.contains(activeItem))) return;
            section.classList.remove('nav-section-collapsed');
            section.setAttribute('aria-expanded', 'true');
            applyVisibility(section, false);
        });
        requestAnimationFrame(() => activeItem.scrollIntoView({ block: 'nearest' }));
    }

    const toggleForSection = (section) => {
        if (!section || !section.classList.contains('nav-section-collapsible')) return;
        const key = section.dataset.sidebarSectionKey;
        if (!key || collectItems(section).length === 0) return;

        const collapsed = !section.classList.contains('nav-section-collapsed');
        section.classList.toggle('nav-section-collapsed', collapsed);
        section.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        applyVisibility(section, collapsed);

        if (!collapsed && getHeaderLevel(section) === 1) {
            collectSubsections(section).forEach((subsection) => {
                if (!subsection.classList.contains('nav-section-collapsible')) return;
                const subKey = subsection.dataset.sidebarSectionKey;
                const subCollapsed = !!subKey && saved[subKey] === 1;
                subsection.classList.toggle('nav-section-collapsed', subCollapsed);
                subsection.setAttribute('aria-expanded', subCollapsed ? 'false' : 'true');
                applyVisibility(subsection, subCollapsed);
            });
        }

        saved[key] = collapsed ? 1 : 0;
        persistLocal();
        persistServer();
    };

    nav.addEventListener('click', (event) => {
        const section = event.target.closest('.nav-section-collapsible');
        if (!section || !nav.contains(section)) return;
        if (event.target !== section) return;
        toggleForSection(section);
    });

    nav.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const section = event.target.closest('.nav-section-collapsible');
        if (!section || !nav.contains(section)) return;
        if (event.target !== section) return;
        event.preventDefault();
        toggleForSection(section);
    });
})();

// ── Toast ─────────────────────────────────────────────────────
function showToast(type, msg, duration = 4000) {
    let area = document.getElementById('toast-area');
    if (!area) { area = document.createElement('div'); area.id = 'toast-area'; document.body.appendChild(area); }
    const el = document.createElement('div');
    const icon = type === 'success' ? 'check-circle' : type === 'warn' ? 'exclamation-triangle' : 'x-circle';
    el.className = `toast-msg ${type}`;
    el.innerHTML = `<i class="bi bi-${icon}"></i><span>${msg}</span>`;
    area.appendChild(el);
    setTimeout(() => { el.style.cssText = 'opacity:0;transition:opacity .4s'; setTimeout(() => el.remove(), 400); }, duration);
}

// ── Collapsible sections ──────────────────────────────────────
function toggleSection(hdr) {
    const body = hdr.nextElementSibling;
    const hidden = body.style.display === 'none';
    body.style.display = hidden ? '' : 'none';
    hdr.classList.toggle('collapsed', !hidden);
}
document.querySelectorAll('.card-header-dvd[data-collapsible]').forEach(h => {
    h.addEventListener('click', () => toggleSection(h));
});

// ── Sidebar mobile toggle ─────────────────────────────────────
const sidebarToggle = document.getElementById('sidebar-toggle');
if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
        document.getElementById('sidebar').classList.toggle('open');
    });
}

// ── Confirm delete/action ────────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
        if (!confirm(el.dataset.confirm || 'Opravdu smazat?')) e.preventDefault();
    });
    // Also prevent form submission for form elements with data-confirm
    if (el.tagName === 'BUTTON' && el.form) {
        el.addEventListener('click', function(e) {
            if (this.dataset.confirm && !confirm(this.dataset.confirm)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }
});

// ── CSV export helper ─────────────────────────────────────────
function triggerCSV(url) { window.location.href = url; }

// ── Generic AJAX fetch JSON ───────────────────────────────────
async function fetchJSON(url, options = {}) {
    const r = await fetch(url, options);
    return r.json();
}

// ── Table row highlight ───────────────────────────────────────
document.querySelectorAll('.tbl tbody tr[data-href]').forEach(tr => {
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', () => window.location.href = tr.dataset.href);
});
