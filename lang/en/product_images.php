<?php

/*
 * KEYS: product_images.* — the Product Image Studio (bulk AI image generation + review).
 * `he` mirrors EVERY key 1:1 (release-blocker parity).
 */

return [

    'nav' => 'Image Studio',
    'title' => 'Product Image Studio',
    'heading' => 'Generate product images with AI',
    'sub' => 'Turn your existing product photos into clean packshots, or render your products on a model. Pick the products, pick the photo, and review every result before you use it.',

    // The money contract — stated plainly, before anything is generated.
    'charge_notice' => 'Each image is charged when the AI succeeds — even if you reject it afterwards. Rejecting an image does not refund it. A generation that fails is never charged.',

    'balance' => 'Available credit',

    'operation' => [
        'packshot_generation' => 'Clean packshot',
        'on_model_generation' => 'Product on a model',
    ],

    'source' => [
        'main' => 'Main product image',
        'alt_1' => '1st additional image',
        'alt_2' => '2nd additional image',
        'alt_3' => '3rd additional image',
    ],

    'generate' => [
        'action' => 'Generate images',
        'heading' => 'Generate product images',
        'sub' => 'Choose what to generate, which photo to use, and for which products. You will see the estimated cost before it runs.',
        'cta' => 'Generate',
        'operation' => 'What to generate',
        'style' => 'Choose a style',
        'aspect' => 'Image proportions',
        'quality' => 'Image quality',
        'notes' => 'Notes (optional)',
        'notes_help' => 'Fine-tune the look — e.g. a background colour like #f5f5f0, softer shadows, a specific angle. This is added to the prompt for every image in this batch.',
        'source' => 'Which photo to transform',
        'source_help' => 'A product with no image in that slot is skipped — never generated from a different photo.',
        'products' => 'Products',
        'products_help' => 'Only your active products are listed.',
        'estimate' => 'Estimated cost',
        'estimate_empty' => 'Pick products to see the estimate.',
        'estimate_line' => ':count image(s) · about :total (:each each) · your balance: :balance',
        'estimate_short' => 'That is more than your available credit — you can generate about :affordable now.',
    ],

    'progress' => [
        'heading' => 'Generating…',
        'sub' => ':operation — this runs in the background; you can leave this page.',
        'counts' => ':settled of :total finished · :succeeded ready · :failed failed · :skipped skipped',
    ],

    'review' => [
        'heading' => 'Review',
        'sub' => 'Approve the images you want to keep. Approved images are the ones that can be published to your store.',
        'awaiting' => 'Awaiting review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'failed' => 'Failed',
        'filter_all' => 'All',
        'approve_all' => 'Approve all pending',
        'reject_all' => 'Reject all pending',
        'approve_all_heading' => 'Approve every image awaiting review?',
        'reject_all_heading' => 'Reject every image awaiting review?',
        'reject_all_sub' => 'Rejecting does not refund the generation — the AI already ran.',
        'empty' => 'No generated images yet',
        'empty_sub' => 'Run a batch and the results will appear here for review.',
        'rendering_heading' => 'In progress',
        'rendering_sub' => 'These are being generated right now — they will move to Review below the moment each one is ready.',
    ],

    'review_status' => [
        'awaiting_review' => 'Awaiting review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'tile' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'regenerate' => 'Regenerate',
        'regenerate_confirm' => 'Generate this image again? The AI runs again, so it is charged again.',
        'no_image' => 'No image',
        'push' => 'Add to store',
        'repush' => 'Try again',
        'undo' => 'Restore original images',
        'undo_confirm' => 'Restore this product\'s original images? Everything Vsio added to this product is removed from your store, the original photos come back, and the original main image is restored. Nothing is charged.',
        'delete' => 'Delete',
        'delete_confirm' => 'Delete this image for good? It cannot be recovered. The generation was already charged, so this does not refund it.',
        'rendering' => 'Rendering…',
        'enlarge' => 'View larger',
        'broken' => 'This image is no longer available',
    ],

    // The full-screen preview when an image is clicked.
    'lightbox' => [
        'close' => 'Close',
    ],

    // Pushing an approved image into the store's product media. It is FREE — the AI was already
    // charged when it succeeded; adding, re-adding or removing the image costs nothing.
    'push' => [
        'heading' => 'Add this image to your store',
        'sub' => 'Choose where it goes in the product gallery. This does not run the AI again and is not charged.',
        'cta' => 'Add to store',
        'placement' => 'Where should it go?',
        'placement_help' => 'Adding to the end is the safe choice — nothing you already have is touched.',
        'position' => 'Position',
        'position_help' => 'Position 1 is the main image shoppers see first.',
        'position_option' => 'Position :n',
        'position_option_last' => 'Position :n (last)',
        'replace' => 'Which image should it replace?',
        'replace_help' => 'The image you pick is removed from your store once the new one is live.',
        'replace_option' => ':n — :alt',
        'replace_option_untitled' => ':n — (no description)',
        'warning' => 'This changes the product page shoppers see. Vsio first saves a copy of your original images, so you can always press “Restore original images”.',
        'gallery_empty' => 'This product has no images in Shopify yet, so the new image can only be added at the end.',
        'status' => 'In store',
        'error' => 'Could not add to store',
    ],

    'placement' => [
        'append' => 'Add at the end',
        'position' => 'Insert at a position',
        'replace' => 'Replace an existing image',
    ],

    'push_status' => [
        'not_pushed' => 'Not in store',
        'pushing' => 'Adding to store…',
        'pushed' => 'In store',
        'push_failed' => 'Could not add',
    ],

    'notify' => [
        'queued' => ':count image(s) queued',
        'queued_body' => 'They are generating in the background — this page updates as they finish.',
        'skipped' => ':count product(s) were skipped: they have no image in that slot, or that exact image already exists.',
        'denied' => 'Not enough credit for this batch',
        'denied_body' => 'This batch needs about :needed and you have :have. You can generate about :affordable images now, or top up your credit.',
        'inactive' => 'This account is not active — no images can be generated.',
        'nothing' => 'Nothing to generate: none of the selected products has an image in that slot.',
        'approved' => 'Image approved',
        'rejected' => 'Image rejected — the generation is still charged.',
        'reject_pushed' => 'This image is live in your store. Undo the push first, then reject it.',
        'deleted' => 'Image deleted',
        'delete_in_store' => 'This image is live in your store. Undo the push first, then delete it.',
        'bulk' => ':count image(s) updated',
        'regenerating' => 'Regenerating — this is a new, separately charged image.',
        // A second Regenerate click while the first render is still running: nothing new was
        // queued and nothing was charged twice — the image on its way IS the one you asked for.
        'still_rendering' => 'That image is still generating — nothing was queued or charged again.',

        // The store rail. Every one of these is FREE — no credit is used to add, re-add or
        // remove an image from your store.
        'pushing' => 'Adding the image to your store — this takes a moment.',
        'push_not_approved' => 'Approve the image first — only approved images go to your store.',
        'push_already' => 'That image is already in your store.',
        'push_in_flight' => 'That image is already on its way to your store — nothing was queued again.',
        'push_not_shopify' => 'This product did not come from Shopify, so it cannot be pushed to a store.',
        'repushing' => 'Trying again — only the upload is retried. The image is not generated again and nothing is charged.',
        'undoing' => 'Restoring your original images — the product page will be back to how it was in a moment.',
        'undo_nothing' => 'Nothing to restore: this product\'s images were never changed by Vsio.',
    ],
];
