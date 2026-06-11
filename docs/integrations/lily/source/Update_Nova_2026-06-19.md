# Update from Lily regarding NOVA / WIND response-behaviour

## Previously:
Until now, Nova was returning a generic "Failure Operator" status for almost all unsuccessful transactions. The only exception was the 20€ monthly spending cap, for which a dedicated error was already being provided.

As a result, due to the lack of visibility regarding the actual reason of the failure, we had previously advised partners to continue handling Nova traffic differently from the other operators.

## As of now:
Nova has recently upgraded its platform and is now returning more detailed failure reasons, in line with the practice followed by the other Greek mobile operators. 

You will therefore begin receiving dedicated DLRs such as:
- Barred User
- Insufficient Credit 

This enhancement will provide better visibility into failed charging attempts and facilitate more accurate reporting and troubleshooting. 

## Important changes for integration:
Also, with this change, the same traffic rules that already apply to the other operators should now also be applied to Nova traffic.

Specifically:
- Barred users must be immediately unsubscribed.
- Subscribers with Insufficient Credit may remain active. However, if no successful charge can be completed for a period of two consecutive months, the subscriber should be removed, regardless of whether free MT messages continue to be delivered successfully.
- The existing rule regarding subscribers who cannot receive either chargeable or free messages for 15 consecutive days remains unchanged and should continue to be applied.