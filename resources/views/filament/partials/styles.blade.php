@once
    <style>
        .qb-ui.space-y-5 > * + * { margin-block-start: 1.25rem; }
        .qb-ui .space-y-3 > * + * { margin-block-start: .75rem; }
        .qb-ui .space-y-2 > * + * { margin-block-start: .5rem; }
        .qb-ui .space-y-1 > * + * { margin-block-start: .25rem; }
        .qb-ui .grid.gap-5 { gap: 1.25rem; align-items: start; }
        .qb-ui .grid.gap-4 { gap: 1rem; align-items: start; }
        .qb-ui .grid.gap-3 { gap: .75rem; }
        .qb-ui .grid.gap-2 { gap: .5rem; }
        .qb-ui ol { list-style: decimal inside; }

        @media (min-width: 40rem) {
            .qb-ui .grid.sm\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (min-width: 48rem) {
            .qb-ui .grid.md\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (min-width: 64rem) {
            .qb-ui .grid.lg\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
@endonce
