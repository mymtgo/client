import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

/** v2 badge family: soft categorical pills. Record chips (W–L) are RecordChip, not a badge variant. */
export const badgeVariants = cva(
    'inline-flex items-center justify-center rounded-full border border-transparent px-2.5 py-[2.5px] text-xs font-semibold w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1.5 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] transition-[color,box-shadow] overflow-hidden',
    {
        variants: {
            variant: {
                default: 'bg-primary-soft text-primary-hi',
                secondary: 'bg-muted text-muted-foreground border-border',
                destructive: 'bg-loss-soft text-loss',
                success: 'bg-win-soft text-win',
                outline: 'border-line-2 text-muted-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);
export type BadgeVariants = VariantProps<typeof badgeVariants>
