import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Alert } from "./Alert.vue"
export { default as AlertDescription } from "./AlertDescription.vue"
export { default as AlertTitle } from "./AlertTitle.vue"

/** v2 message anatomy, shared with AppToast: muted surface, line border, 3px semantic left border. */
export const alertVariants = cva(
  "relative w-full rounded-md border border-border border-l-[3px] bg-muted px-3.5 py-3 text-[13px] grid has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        default: "border-l-win text-foreground",
        info: "border-l-primary text-foreground",
        warning: "border-l-warn text-foreground",
        destructive: "border-l-loss text-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)

export type AlertVariants = VariantProps<typeof alertVariants>
