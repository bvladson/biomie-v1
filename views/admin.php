<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light" />
  <meta name="theme-color" content="#f6f3f2" />
  <title>Administra&ccedil;&atilde;o | BIomie MAG</title>
  <link rel="icon" href="images/favicon.svg" type="image/svg+xml" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" media="print" onload="this.media='all'" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" media="print" onload="this.media='all'" />
  <link rel="stylesheet" href="assets/css/theme-tokens.css?v=20260425-ui1" />
  <link rel="stylesheet" href="assets/css/shell-index.css?v=20260415sk5" />
  <link rel="stylesheet" href="assets/css/module-pages.css?v=20260415sk1" />
  <link rel="stylesheet" href="assets/css/mag-design-system.css?v=20260425-ui1" />
  <style>
  /* ── Admin shell ─────────────────────────────────────────────────────────── */
  /* Área útil maior, porém ainda centralizada e legível. */
  .admin-wrap{padding:24px;max-width:1680px;margin:0 auto}
  /* A barra de rolagem some numa aba curta e volta numa aba longa — isso desloca
     a página inteira ~15px e dá a sensação de que cada aba tem um tamanho. Reservar
     a calha mantém a largura IDÊNTICA em todas as abas, com ou sem rolagem. */
  html{scrollbar-gutter:stable}

  /* ── Tabs ────────────────────────────────────────────────────────────────── */
  .admin-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:16px 0 20px;padding:4px;background:var(--mag-bg,#f8f9fb);border-radius:14px;border:1px solid var(--mag-border,#e5e7eb)}
  .admin-tab{border:1px solid transparent;background:transparent;color:var(--mag-muted,#64748b);border-radius:10px;padding:9px 14px;font-weight:700;font-size:.84rem;display:inline-flex;align-items:center;gap:7px;cursor:pointer;transition:all .14s;white-space:nowrap}
  .admin-tab:hover{background:var(--mag-surface,#fff);color:var(--mag-text,#0f172a)}
  .admin-tab.active{background:var(--mag-surface,#fff);border-color:var(--mag-border,#e5e7eb);color:var(--page-accent,#96140a);box-shadow:0 1px 4px rgba(0,0,0,.07)}
  .admin-tab .material-symbols-rounded{font-size:18px}
  .admin-section{display:none}.admin-section.active{display:block}

  /* ── Navegação agrupada (ADMIN.R1) ───────────────────────────────────────── */
  /* Duas colunas: seções à esquerda, painel à direita. Em telas estreitas vira
     uma coluna só, com a navegação rolando na horizontal — sem esconder nada. */
  .admin-layout{display:grid;grid-template-columns:236px minmax(0,1fr);gap:20px;align-items:start;margin-top:16px}
  /* Altura mínima comum: sem isso, uma aba curta (ex.: Migrations) encolhe a
     página e a seguinte "estica" de novo — a troca de aba parecia mudar de
     tamanho. Com o piso, toda aba ocupa ao menos a altura visível. */
  .admin-content{min-width:0;min-height:calc(100vh - 320px)}
  .admin-nav{position:sticky;top:16px;display:flex;flex-direction:column;gap:16px;
    padding:12px;background:var(--mag-bg,#f8f9fb);border:1px solid var(--mag-border,#e5e7eb);border-radius:14px}
  .admin-nav__group{display:flex;flex-direction:column;gap:2px}
  .admin-nav__title{display:flex;align-items:center;gap:6px;margin:0 0 4px;padding:0 8px;
    font-size:.68rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--mag-muted,#64748b)}
  .admin-nav__title .material-symbols-rounded{font-size:15px}
  /* Sistema avançado é fronteira de privilégio: separado por uma linha, não só
     pela posição — para o admin comercial perceber que ali é outro território. */
  .admin-nav__group--advanced{border-top:1px dashed var(--mag-border,#e5e7eb);padding-top:14px;margin-top:2px}
  .admin-nav__item{display:flex;align-items:center;gap:9px;width:100%;text-align:left;
    border:1px solid transparent;background:transparent;color:var(--mag-text,#0f172a);
    border-radius:9px;padding:7px 9px;font-weight:600;font-size:.83rem;cursor:pointer;transition:background .13s,color .13s}
  .admin-nav__item:hover{background:var(--mag-surface,#fff)}
  .admin-nav__item.active{background:var(--mag-surface,#fff);border-color:var(--mag-border,#e5e7eb);
    color:var(--page-accent,#96140a);font-weight:800;box-shadow:0 1px 4px rgba(0,0,0,.06)}
  .admin-nav__item .material-symbols-rounded{font-size:18px;flex:0 0 auto}
  .admin-nav__item span:last-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .admin-nav__item:focus-visible{outline:2px solid var(--page-accent,#96140a);outline-offset:1px}
  @media (max-width:900px){
    .admin-layout{grid-template-columns:minmax(0,1fr)}
    .admin-nav{position:static;flex-direction:row;overflow-x:auto;gap:14px;padding:10px}
    .admin-nav__group{flex-direction:row;align-items:center;gap:4px;flex:0 0 auto}
    .admin-nav__group--advanced{border-top:0;border-left:1px dashed var(--mag-border,#e5e7eb);padding-top:0;padding-left:14px;margin-top:0}
    .admin-nav__title{display:none}
    .admin-nav__item{white-space:nowrap;width:auto}
  }

  /* ── Cards e painéis ─────────────────────────────────────────────────────── */
  .admin-panel{background:var(--mag-surface,#fff);border:1px solid var(--mag-border,#e5e7eb);border-radius:16px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
  .admin-panel + .admin-panel{margin-top:16px}
  .admin-panel h2{margin:0 0 16px;font-size:1rem;font-weight:800;color:var(--mag-text,#0f172a);display:flex;align-items:center;gap:8px}
  .admin-panel h2 .material-symbols-rounded{font-size:20px;color:var(--page-accent,#96140a)}

  /* ── Formulários ─────────────────────────────────────────────────────────── */
  .admin-grid{display:grid;grid-template-columns:360px 1fr;gap:18px}
  .admin-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  .admin-field{display:grid;gap:7px;margin-bottom:12px}
  .admin-field label{font-size:.8rem;font-weight:700;color:var(--mag-muted,#64748b);text-transform:uppercase;letter-spacing:.04em}
  .admin-input,.admin-select{width:100%;border:1px solid var(--mag-border,#e5e7eb);border-radius:10px;background:var(--mag-bg,#f8f9fb);color:var(--mag-text,#0f172a);padding:10px 12px;font:inherit;font-size:.9rem;transition:border-color .14s}
  .admin-input:focus,.admin-select:focus{outline:none;border-color:var(--page-accent,#96140a);box-shadow:0 0 0 3px var(--page-accent-soft,rgba(150,20,10,.1))}
  .admin-check{display:flex;align-items:center;gap:8px;color:var(--mag-text);font-weight:700}
  .admin-check input{accent-color:var(--page-accent,#96140a)}
  .admin-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}

  /* ── Tabelas ─────────────────────────────────────────────────────────────── */
  .admin-table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--mag-border,#e5e7eb)}
  .admin-table{width:100%;border-collapse:collapse}
  .admin-table th{background:var(--mag-bg,#f8f9fb);color:var(--mag-muted,#64748b);font-size:.73rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:10px 12px;border-bottom:1px solid var(--mag-border,#e5e7eb);white-space:nowrap}
  .admin-table td{padding:11px 12px;border-bottom:1px solid var(--mag-border,#e5e7eb);color:var(--mag-text,#0f172a);font-size:.88rem;vertical-align:middle}
  .admin-table tbody tr:last-child td{border-bottom:none}
  .admin-table tbody tr:hover{background:var(--mag-bg,#f8f9fb)}
  .admin-table-compact td,.admin-table-compact th{padding:8px 12px}

  /* ── Tags de status ──────────────────────────────────────────────────────── */
  .admin-tag{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:4px 10px;font-size:.72rem;font-weight:700;white-space:nowrap}
  .admin-tag.neutral{background:var(--mag-bg,#f1f5f9);color:var(--mag-muted,#64748b);border:1px solid var(--mag-border)}
  .admin-tag.ok{background:#ecfdf5;color:#065f46;border:1px solid rgba(6,95,70,.18)}
  .admin-tag.warn,.admin-tag.warning{background:#fffbeb;color:#78350f;border:1px solid rgba(120,53,15,.18)}
  .admin-tag.danger,.admin-tag.critical,.admin-tag.error{background:#fef2f2;color:#991b1b;border:1px solid rgba(153,27,27,.18)}
  .admin-tag.pending{background:#eff6ff;color:#1e40af;border:1px solid rgba(30,64,175,.18)}

  /* ── Flash messages ──────────────────────────────────────────────────────── */
  .admin-msg{margin:0 0 16px;border-radius:12px;padding:12px 16px;font-weight:700;display:flex;align-items:center;gap:8px}
  .admin-msg.success{background:#ecfdf5;color:#065f46;border:1px solid rgba(6,95,70,.18)}
  .admin-msg.error{background:#fef2f2;color:#991b1b;border:1px solid rgba(153,27,27,.18)}
  .admin-msg.warning{background:#fffbeb;color:#78350f;border:1px solid rgba(120,53,15,.18)}
  .admin-msg.info{background:var(--page-accent-soft);color:var(--page-accent);border:1px solid var(--page-accent-border)}

  /* ── Grids ───────────────────────────────────────────────────────────────── */
  .admin-roles-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  .admin-permission-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
  .admin-permission-item{border:1px solid var(--mag-border);border-radius:12px;padding:12px;background:var(--mag-bg)}
  .admin-permission-item label{display:flex;align-items:flex-start;gap:8px;font-weight:700;color:var(--mag-text);cursor:pointer}
  .admin-diagnostics-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .admin-diagnostic-card{border:1px solid var(--mag-border);border-radius:14px;padding:16px;background:var(--mag-bg)}
  .admin-diagnostic-card span{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--mag-muted);letter-spacing:.05em}
  .admin-diagnostic-card strong{display:block;margin-top:6px;color:var(--mag-text);font-size:1.15rem;font-weight:800}

  /* ── Ops cards ───────────────────────────────────────────────────────────── */
  .admin-ops-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
  .admin-ops-head > div > p{margin:4px 0 0}
  .admin-ops-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
  .admin-ops-grid.two{grid-template-columns:1.35fr .95fr}
  .admin-ops-card{border:1px solid var(--mag-border,#e5e7eb);border-radius:14px;background:var(--mag-surface,#fff);padding:16px;min-height:100px;position:relative;overflow:hidden;transition:box-shadow .14s}
  .admin-ops-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--mag-border)}
  .admin-ops-card.ok::before{background:#22c55e}
  .admin-ops-card.warning,.admin-ops-card.warn{}.admin-ops-card.warning::before,.admin-ops-card.warn::before{background:#f59e0b}
  .admin-ops-card.critical::before,.admin-ops-card.danger::before,.admin-ops-card.error::before{background:#ef4444}
  .admin-ops-card span{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mag-muted)}
  .admin-ops-card strong{display:block;margin-top:8px;color:var(--mag-text);font-size:1.18rem;font-weight:800;line-height:1.2}
  .admin-ops-card p{margin:6px 0 0;font-size:.8rem;color:var(--mag-muted);line-height:1.4}
  .admin-ops-block{border-top:1px solid var(--mag-border);padding-top:16px;margin-top:16px}
  .admin-ops-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
  .admin-ops-section-title h3{margin:0;color:var(--mag-text);font-size:.95rem;font-weight:800;display:flex;align-items:center;gap:7px}
  .admin-ops-section-title h3 .material-symbols-rounded{font-size:18px;color:var(--page-accent)}
  .admin-ops-list{display:grid;gap:8px}
  .admin-ops-list-item{border:1px solid var(--mag-border);border-radius:10px;background:var(--mag-bg);padding:10px 14px}
  .admin-ops-list-item strong{display:block;color:var(--mag-text);font-size:.88rem}
  .admin-ops-details-body{margin-top:10px}
  .admin-ops-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  .admin-ops-details{border:1px solid var(--mag-border);border-radius:10px;background:var(--mag-bg);padding:10px 14px;margin-top:8px}
  .admin-ops-details summary{cursor:pointer;font-weight:700;font-size:.88rem;color:var(--mag-text)}
  .admin-ops-pre{white-space:pre-wrap;word-break:break-word;font-size:.75rem;font-family:'DM Mono',monospace;background:#0f172a;color:#94a3b8;border-radius:10px;padding:14px;max-height:340px;overflow:auto;margin-top:10px}
  .admin-ops-link-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  .admin-ops-link{display:flex;align-items:flex-start;gap:10px;text-decoration:none;color:inherit;border:1px solid var(--mag-border);border-radius:14px;background:var(--mag-bg);padding:16px;transition:all .14s}
  .admin-ops-link:hover{background:var(--mag-surface);border-color:var(--page-accent-border);box-shadow:0 2px 8px rgba(0,0,0,.08)}
  .admin-ops-link .material-symbols-rounded{color:var(--page-accent);font-size:22px;flex-shrink:0}
  .admin-ops-link strong{display:block;font-size:.9rem;color:var(--mag-text)}

  /* ── Botões de linha e ações ─────────────────────────────────────────────── */
  .admin-row-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
  .admin-row-btn{padding:5px 10px;border-radius:8px;font-size:.78rem;font-weight:700;border:1px solid var(--mag-border);background:var(--mag-surface);color:var(--mag-text);cursor:pointer;display:inline-flex;align-items:center;gap:5px;line-height:1.4;white-space:nowrap;transition:all .12s}
  .admin-row-btn:hover{background:var(--page-accent-soft);color:var(--page-accent);border-color:var(--page-accent-border)}
  .admin-row-btn.danger{color:#991b1b}
  .admin-row-btn.danger:hover{background:#fef2f2;border-color:rgba(153,27,27,.25)}
  .admin-row-btn.primary{background:var(--page-accent);color:#fff;border-color:var(--page-accent)}
  .admin-row-btn.primary:hover{background:#7a0f08}
  .admin-row-btn:disabled{opacity:.55;cursor:not-allowed}

  /* ── Misc ────────────────────────────────────────────────────────────────── */
  .admin-small{font-size:.8rem;color:var(--mag-muted,#64748b);line-height:1.45}
  .admin-inline-form{display:grid;gap:8px;min-width:300px}
  .admin-user-actions{display:grid;gap:8px}
  .admin-user-cell{display:flex;flex-direction:column;gap:3px}

  /* ── Modais ──────────────────────────────────────────────────────────────── */
  .admin-modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:999;padding:16px}
  .admin-modal[hidden]{display:none}
  .admin-modal-box{background:var(--mag-surface);border:1px solid var(--mag-border);border-radius:20px;padding:24px;width:100%;max-width:440px;box-shadow:0 12px 48px rgba(0,0,0,.22);position:relative;max-height:90vh;overflow-y:auto}
  .admin-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:12px}
  .admin-modal-head h3{margin:0;font-size:.95rem;font-weight:800;color:var(--mag-text);display:flex;align-items:center;gap:7px}
  .admin-modal-close{background:none;border:none;cursor:pointer;color:var(--mag-muted);padding:4px;border-radius:8px;display:flex;align-items:center;transition:all .12s}
  .admin-modal-close:hover{background:var(--mag-bg);color:var(--mag-text)}

  /* ── Control Center (Cockpit) ────────────────────────────────────────────── */
  .cc-hero{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;background:linear-gradient(135deg,#0f172a 0%,#1a2744 60%,#1e1a2e 100%);border-radius:16px;margin-bottom:16px;color:#fff}
  .cc-hero__status{display:flex;align-items:center;gap:12px;min-width:0}
  .cc-status-dot{width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.25);flex-shrink:0;transition:background .3s}
  .cc-status-dot.warn{background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.25)}
  .cc-status-dot.danger{background:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.25)}
  .cc-status-dot.unknown{background:#94a3b8;box-shadow:none}
  .cc-hero__title{font-size:1rem;font-weight:800;color:#fff;margin:0 0 2px}
  .cc-hero__sub{font-size:.8rem;color:#94a3b8;margin:0}
  .cc-hero__meta{display:flex;align-items:center;gap:10px;flex-shrink:0}
  .cc-hero__meta .admin-small{color:#94a3b8}
  .cc-hero .admin-row-btn{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:#fff}
  .cc-hero .admin-row-btn:hover{background:rgba(255,255,255,.2)}
  .cc-auto-badge{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:700;color:#64748b;background:var(--mag-bg);border:1px solid var(--mag-border);border-radius:999px;padding:3px 10px}

  /* KPI strip */
  .cc-kpi-strip{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:16px}
  .cc-kpi{border:1px solid var(--mag-border);border-radius:14px;background:var(--mag-surface);padding:16px 14px;position:relative;overflow:hidden;cursor:default}
  .cc-kpi__accent{position:absolute;top:0;left:0;right:0;height:3px;background:var(--mag-border);border-radius:14px 14px 0 0;transition:background .3s}
  .cc-kpi.ok .cc-kpi__accent{background:#22c55e}
  .cc-kpi.warn .cc-kpi__accent{background:#f59e0b}
  .cc-kpi.danger .cc-kpi__accent{background:#ef4444}
  .cc-kpi__icon{font-size:20px;color:var(--mag-muted);margin-bottom:8px;display:block}
  .cc-kpi__label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mag-muted);margin-bottom:4px}
  .cc-kpi__value{font-size:1.2rem;font-weight:800;color:var(--mag-text);line-height:1.1}
  .cc-kpi__sub{font-size:.75rem;color:var(--mag-muted);margin-top:4px}
  .cc-kpi.ok .cc-kpi__value{color:#065f46}
  .cc-kpi.warn .cc-kpi__value{color:#78350f}
  .cc-kpi.danger .cc-kpi__value{color:#991b1b}

  /* Quick actions */
  .cc-actions-panel{background:var(--mag-surface);border:1px solid var(--mag-border);border-radius:16px;padding:18px 20px;margin-bottom:16px}
  .cc-section-title{margin:0 0 14px;font-size:.9rem;font-weight:800;color:var(--mag-text);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .cc-section-title .material-symbols-rounded{font-size:18px;color:var(--page-accent)}
  .cc-section-title__badges{display:flex;gap:6px;margin-left:auto}
  .cc-actions-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
  .cc-action-btn{display:flex;flex-direction:column;align-items:flex-start;gap:8px;padding:14px 16px;border:1px solid var(--mag-border);border-radius:14px;background:var(--mag-bg);cursor:pointer;text-align:left;transition:all .14s;font-family:inherit;width:100%}
  .cc-action-btn:hover{background:var(--page-accent-soft);border-color:var(--page-accent-border);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.08)}
  .cc-action-btn:active{transform:translateY(0)}
  .cc-action-btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
  .cc-action-btn__icon{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:var(--page-accent-soft);color:var(--page-accent)}
  .cc-action-btn__icon .material-symbols-rounded{font-size:20px}
  .cc-action-btn__label{font-size:.84rem;font-weight:800;color:var(--mag-text);line-height:1.2}
  .cc-action-btn__desc{font-size:.75rem;color:var(--mag-muted)}
  .cc-action-btn.running .cc-action-btn__icon{animation:cc-spin .8s linear infinite}
  @keyframes cc-spin{to{transform:rotate(360deg)}}
  .cc-action-feedback{margin-top:12px;border-radius:12px;padding:12px 14px;font-size:.84rem;font-weight:700}
  .cc-action-feedback.ok{background:#ecfdf5;color:#065f46;border:1px solid rgba(6,95,70,.18)}
  .cc-action-feedback.error{background:#fef2f2;color:#991b1b;border:1px solid rgba(153,27,27,.18)}
  .cc-action-feedback.warn{background:#fffbeb;color:#78350f;border:1px solid rgba(120,53,15,.18)}
  .cc-action-feedback.info{background:var(--page-accent-soft);color:var(--page-accent);border:1px solid var(--page-accent-border)}

  /* Two col layout */
  .cc-grid-two{display:grid;grid-template-columns:1.4fr 1fr;gap:16px;margin-bottom:16px}
  .cc-panel{background:var(--mag-surface);border:1px solid var(--mag-border);border-radius:16px;padding:18px 20px}

  /* Health list */
  .cc-health-list{display:grid;gap:6px}
  .cc-health-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:10px;border:1px solid var(--mag-border);background:var(--mag-bg)}
  .cc-health-item.ok{border-color:rgba(6,95,70,.15);background:#f0fdf4}
  .cc-health-item.warn{border-color:rgba(120,53,15,.15);background:#fffbeb}
  .cc-health-item.danger,.cc-health-item.critical,.cc-health-item.error{border-color:rgba(153,27,27,.15);background:#fef2f2}
  .cc-health-icon{font-size:18px;flex-shrink:0;margin-top:1px}
  .cc-health-item.ok .cc-health-icon{color:#16a34a}
  .cc-health-item.warn .cc-health-icon{color:#d97706}
  .cc-health-item.danger .cc-health-icon,.cc-health-item.critical .cc-health-icon,.cc-health-item.error .cc-health-icon{color:#dc2626}
  .cc-health-item.neutral .cc-health-icon{color:var(--mag-muted)}
  .cc-health-label{font-size:.84rem;font-weight:700;color:var(--mag-text)}
  .cc-health-msg{font-size:.76rem;color:var(--mag-muted);margin-top:2px}
  .cc-health-badges{display:flex;gap:6px;margin-left:auto;align-items:center}

  /* Recent ops */
  .cc-recent-list{display:grid;gap:6px}
  .cc-recent-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;border:1px solid var(--mag-border);background:var(--mag-bg)}
  .cc-recent-icon{font-size:16px;flex-shrink:0;color:var(--mag-muted)}
  .cc-recent-icon.ok{color:#16a34a}
  .cc-recent-icon.error{color:#dc2626}
  .cc-recent-label{font-size:.84rem;font-weight:700;color:var(--mag-text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .cc-recent-time{font-size:.75rem;color:var(--mag-muted);flex-shrink:0}

  /* Domain table */
  .cc-domains{display:grid;gap:8px}
  .cc-domain-row{display:grid;grid-template-columns:28px 1fr auto auto auto;align-items:center;gap:12px;padding:12px 16px;border:1px solid var(--mag-border);border-radius:12px;background:var(--mag-bg);transition:background .12s}
  .cc-domain-row:hover{background:var(--mag-surface)}
  .cc-domain-icon{font-size:20px;color:var(--mag-muted)}
  .cc-domain-info{}
  .cc-domain-name{font-size:.88rem;font-weight:800;color:var(--mag-text)}
  .cc-domain-age{font-size:.75rem;color:var(--mag-muted)}
  .cc-domain-actions{display:flex;gap:6px}

  /* Log filters */
  .admin-log-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px 16px;background:var(--mag-bg);border-radius:12px;border:1px solid var(--mag-border);margin-bottom:12px}
  .admin-log-filters .admin-input,.admin-log-filters .admin-select{min-width:0;flex:1 1 140px;max-width:220px}
  .admin-log-filter-clear{margin-left:auto}

  /* Responsive */
  @media(max-width:1180px){
    .admin-grid{grid-template-columns:1fr}
    .admin-roles-grid,.admin-diagnostics-grid,.admin-ops-grid,.admin-ops-link-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .admin-ops-grid.two{grid-template-columns:1fr}
    .admin-permission-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .cc-kpi-strip{grid-template-columns:repeat(3,minmax(0,1fr))}
    .cc-actions-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .cc-grid-two{grid-template-columns:1fr}
  }
  @media(max-width:720px){
    .admin-wrap{padding:16px}
    .admin-form-grid,.admin-roles-grid,.admin-diagnostics-grid,.admin-ops-grid,.admin-ops-link-grid,.admin-permission-grid,.cc-kpi-strip,.cc-actions-grid,.cc-grid-two{grid-template-columns:1fr}
    .admin-tab{flex:1 1 auto;justify-content:center;font-size:.78rem;padding:8px 10px}
    .admin-inline-form{min-width:0}
    .admin-modal-box{padding:18px;border-radius:14px}
    .admin-ops-head,.cc-hero{display:block}
    .admin-ops-head .admin-actions,.cc-hero__meta{margin-top:12px}
    .cc-domain-row{grid-template-columns:1fr auto}
    .cc-domain-icon{display:none}
  }
  </style>
  <script src="assets/js/pwa.js?v=20260415sk1" defer></script>
</head>
<?php
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$roles            = is_array($roles            ?? null) ? $roles            : [];
$permissions      = is_array($permissions      ?? null) ? $permissions      : [];
$permissionMatrix = is_array($permissionMatrix ?? null) ? $permissionMatrix : [];
$can              = is_array($can              ?? null) ? $can              : ['users' => false, 'logs' => false, 'migrations' => false, 'console' => false, 'operations' => false];
$tabAccess = [
    'users'       => (bool) ($can['users']      ?? false),
    'access'      => (bool) ($can['users']      ?? false),
    'seller-binding' => (bool) ($can['users']   ?? false),
    'profiles'    => (bool) ($can['users']      ?? false),
    'permissions' => (bool) ($can['users']      ?? false),
    'logs'        => (bool) ($can['logs']       ?? false),
    'diagnostics' => (bool) ($can['logs']       ?? false),
    'migrations'  => (bool) ($can['migrations'] ?? false),
    'console'     => (bool) ($can['console']    ?? false),
    'system-health' => (bool) ($can['operations'] ?? false),
    'engine-health' => (bool) ($can['operations'] ?? false),
    'integrations'  => (bool) ($can['operations'] ?? false),
    'metrics'       => (bool) ($can['operations'] ?? false),
    'cockpit'       => (bool) ($can['operations'] ?? false),
];
/**
 * NAVEGAÇÃO AGRUPADA (ADMIN.R1) — substitui a linha única de 14 abas.
 *
 * Agrupar por INTENÇÃO, não por implementação: quem cuida de gente não deveria
 * tropeçar em migrations e console no meio do caminho. "Sistema avançado" fica
 * por último e só aparece para quem tem a capability técnica — a separação de
 * privilégio já existe em admin.migrations / admin.console, então o grupo só
 * torna visível uma fronteira que o RBAC já aplicava.
 *
 * Cada item continua sendo um `data-admin-tab-trigger` — o contrato de troca de
 * painel não muda, então nenhum painel existente precisou ser reescrito.
 */
$tabGroups = [
    [
        'label' => 'Vis&atilde;o geral',
        'icon'  => 'dashboard',
        'keys'  => ['cockpit'],
    ],
    [
        'label' => 'Pessoas e acessos',
        'icon'  => 'group',
        'keys'  => ['users', 'access', 'seller-binding', 'profiles', 'permissions'],
    ],
    [
        'label' => 'Seguran&ccedil;a e auditoria',
        'icon'  => 'shield',
        'keys'  => ['logs', 'diagnostics'],
    ],
    [
        'label' => 'Opera&ccedil;&atilde;o do sistema',
        'icon'  => 'monitor_heart',
        'keys'  => ['system-health', 'engine-health', 'integrations', 'metrics'],
    ],
    [
        // Só aparece para quem tem migrations/console — separação de privilégio.
        'label' => 'Sistema avan&ccedil;ado',
        'icon'  => 'terminal',
        'keys'  => ['migrations', 'console'],
        'advanced' => true,
    ],
];
$tabs = [
    ['key' => 'cockpit',       'label' => 'Cockpit',                  'icon' => 'dashboard_customize'],
    ['key' => 'system-health', 'label' => 'Sa&uacute;de',             'icon' => 'monitor_heart'],
    ['key' => 'engine-health', 'label' => 'Sa&uacute;de do Motor',    'icon' => 'cardiology'],
    ['key' => 'integrations',  'label' => 'Integra&ccedil;&otilde;es','icon' => 'hub'],
    ['key' => 'console',       'label' => 'Console',                  'icon' => 'terminal'],
    ['key' => 'metrics',       'label' => 'KPIs',                     'icon' => 'query_stats'],
    ['key' => 'users',         'label' => 'Usu&aacute;rios',          'icon' => 'group'],
    ['key' => 'access',        'label' => 'Acessos &amp; Empresas',   'icon' => 'admin_panel_settings'],
    ['key' => 'seller-binding','label' => 'V&iacute;nculo Vendedores','icon' => 'link'],
    ['key' => 'profiles',      'label' => 'Perfis',                   'icon' => 'badge'],
    ['key' => 'permissions',   'label' => 'Permiss&otilde;es',        'icon' => 'key'],
    ['key' => 'logs',          'label' => 'Logs',                     'icon' => 'receipt_long'],
    ['key' => 'diagnostics',   'label' => 'Diagn&oacute;stico',       'icon' => 'monitor_heart'],
    ['key' => 'migrations',    'label' => 'Migrations',               'icon' => 'database'],
];
$consoleCommandList = is_array($consoleCommandList ?? null) ? $consoleCommandList : [];
$activeTab          = (string) ($activeTab ?? 'users');
if (!isset($tabAccess[$activeTab]) || !$tabAccess[$activeTab]) {
    if ($tabAccess['cockpit'] ?? false) {
        $activeTab = 'cockpit';
    } else {
        $activeTab = array_key_first(array_filter($tabAccess)) ?: 'users';
    }
}
$roleByKey = [];
foreach ($roles as $roleRow) {
    $roleByKey[(string) ($roleRow['role_key'] ?? '')] = $roleRow;
}
$permissionByKey = [];
foreach ($permissions as $permissionRow) {
    $permissionByKey[(string) ($permissionRow['permission_key'] ?? '')] = $permissionRow;
}
$roleLabel = static function (string $roleKey) use ($roleByKey): string {
    return (string) ($roleByKey[$roleKey]['name'] ?? \BIomie\Acl\Roles::label($roleKey));
};
$statusTag   = static fn (bool $active): string => $active ? '<span class="admin-tag ok">ativo</span>' : '<span class="admin-tag danger">inativo</span>';
$statusLabel = static fn (string $status): string => match ($status) {
    'applied', 'ok'                                                          => 'ok',
    'pending', 'warning'                                                     => 'warn',
    'rolled_back', 'missing', 'connection_error', 'checksum_mismatch'       => 'danger',
    default                                                                  => 'neutral',
};
$csrf            = (string) ($csrf            ?? '');
$flash           = is_array($flash           ?? null) ? $flash           : null;
$users           = is_array($users           ?? null) ? $users           : [];
$adminLogs       = is_array($adminLogs       ?? null) ? $adminLogs       : [];
$rbacDiagnostics = is_array($rbacDiagnostics ?? null) ? $rbacDiagnostics : [];
$systemHealth    = is_array($systemHealth    ?? null) ? $systemHealth    : [];
$migrationStatus = is_array($migrationStatus ?? null) ? $migrationStatus : [];
?>
<body class="nav-md mag-page" data-page="admin">
<div class="overlay" id="overlay"></div>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <img src="images/logo-mag-wide.png" alt="MAG Moveis Corporativos" onerror="this.style.display='none'" />
      <span class="logo-pill">BIomie</span>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button class="menu-btn btn-icon" id="menu_toggle" aria-label="Menu"><span class="material-symbols-rounded">menu</span></button>
        <div class="topbar-badge"><span class="material-symbols-rounded">admin_panel_settings</span>Administra&ccedil;&atilde;o</div>
      </div>
      <div class="topbar-actions">
        <a class="btn-secondary" href="logout.php"><span class="material-symbols-rounded">logout</span>Sair</a>
      </div>
    </header>

    <main class="page">
      <div class="admin-wrap">
        <section class="mag-page-header mag-page-header-shell mag-page-title anim">
          <div class="mag-page-title__copy">
            <div class="mag-kicker"><span class="material-symbols-rounded">admin_panel_settings</span>Admin</div>
            <h1>Administra&ccedil;&atilde;o</h1>
            <p>Gerencie usu&aacute;rios, permiss&otilde;es, logs e opera&ccedil;&atilde;o t&eacute;cnica em uma &aacute;rea administrativa &uacute;nica.</p>
          </div>
          <div class="mag-page-title__actions">
            <a class="btn-secondary" href="admin.php?tab=system-health"><span class="material-symbols-rounded">monitoring</span>Sa&uacute;de</a>
            <a class="btn-secondary" href="index.php"><span class="material-symbols-rounded">space_dashboard</span>Painel</a>
          </div>
        </section>

        <?php if ($flash !== null): ?>
          <div class="admin-msg <?php echo $h($flash['type'] ?? 'info'); ?>"><?php echo $h($flash['message'] ?? ''); ?></div>
        <?php endif; ?>

        <?php
        // Índice key → definição, para os grupos renderizarem rótulo e ícone.
        $tabByKey = [];
        foreach ($tabs as $t) {
            $tabByKey[$t['key']] = $t;
        }
        ?>
        <div class="admin-layout">
          <nav class="admin-nav" aria-label="Se&ccedil;&otilde;es administrativas">
            <?php foreach ($tabGroups as $group): ?>
              <?php
              // Só renderiza o grupo se ao menos um item for permitido — evita
              // cabeçalho órfão de seção vazia.
              $visible = array_values(array_filter(
                  $group['keys'],
                  static fn (string $k): bool => ($tabAccess[$k] ?? false) && isset($tabByKey[$k])
              ));
              if ($visible === []) { continue; }
              ?>
              <div class="admin-nav__group<?php echo !empty($group['advanced']) ? ' admin-nav__group--advanced' : ''; ?>">
                <p class="admin-nav__title">
                  <span class="material-symbols-rounded" aria-hidden="true"><?php echo $h($group['icon']); ?></span>
                  <?php echo $group['label']; ?>
                </p>
                <?php foreach ($visible as $key): ?>
                  <button class="admin-nav__item <?php echo $activeTab === $key ? 'active' : ''; ?>"
                          type="button" data-admin-tab-trigger="<?php echo $h($key); ?>">
                    <span class="material-symbols-rounded" aria-hidden="true"><?php echo $h($tabByKey[$key]['icon']); ?></span>
                    <span><?php echo $tabByKey[$key]['label']; ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </nav>

          <div class="admin-content">

        <?php include __DIR__ . '/admin/tabs/users.php'; ?>
        <?php include __DIR__ . '/admin/tabs/access.php'; ?>
        <?php include __DIR__ . '/admin/tabs/seller-binding.php'; ?>
        <?php include __DIR__ . '/admin/tabs/profiles.php'; ?>
        <?php include __DIR__ . '/admin/tabs/permissions.php'; ?>
        <?php include __DIR__ . '/admin/tabs/logs.php'; ?>
        <?php include __DIR__ . '/admin/tabs/diagnostics.php'; ?>
        <?php include __DIR__ . '/admin/tabs/migrations.php'; ?>
        <?php include __DIR__ . '/admin/tabs/console.php'; ?>
        <?php include __DIR__ . '/admin/tabs/system-health.php'; ?>
        <?php include __DIR__ . '/admin/tabs/engine-health.php'; ?>
        <?php include __DIR__ . '/admin/tabs/integrations.php'; ?>
        <?php include __DIR__ . '/admin/tabs/metric-catalog.php'; ?>
        <?php include __DIR__ . '/admin/tabs/cockpit.php'; ?>

          </div><!-- /admin-content -->
        </div><!-- /admin-layout -->
      </div>
    </main>
  </div>
</div>
<script>window.ADMIN_CSRF = '<?php echo addslashes($csrf); ?>';</script>
<script src="assets/js/shell.js?v=20260425-ui1"></script>
<?php include __DIR__ . '/admin/partials/admin-js.php'; ?>
<?php include __DIR__ . '/admin/partials/console-js.php'; ?>
</body>
