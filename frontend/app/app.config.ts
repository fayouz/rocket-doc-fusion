/**
 * Identity of the application and its own menu entries: the rest of the interface (layout, dashboard,
 * administration pages) is shared by every Rocket application.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'indigo',
      neutral: 'zinc',
    },
  },
  rocket: {
    id: 'doc-fusion',
    name: 'Rocket Doc Fusion',
    icon: 'i-lucide-file-stack',
    // Login page subtitle.
    tagline: 'Fusionnez vos modèles Word avec vos données, puis retouchez-les dans ONLYOFFICE.',
    // The editor can be embedded by applications (Rocket Dispatch…): allowed origins in Administration → Applications.
    embed: true,
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [
      { label: 'Documents', type: 'label' },
      { label: 'Fusionner', icon: 'i-lucide-file-stack', to: '/merge' },
      { label: 'Mes documents', icon: 'i-lucide-files', to: '/documents' },
    ] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, exactQuery?: boolean, admin?: boolean }[],
    // Extra entries of the Administration menu.
    adminNavigation: [
      { label: 'ONLYOFFICE', icon: 'i-lucide-file-cog', to: '/onlyoffice' },
    ] as { label: string, icon: string, to: string, exactQuery?: boolean }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['Les paroles s’envolent, les écrits restent.', 'Proverbe latin'],
      ['Ce qui se conçoit bien s’énonce clairement.', 'Nicolas Boileau'],
      ['Vingt fois sur le métier remettez votre ouvrage.', 'Nicolas Boileau'],
    ] as [string, string][],
  },
})
