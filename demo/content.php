<?php

/**
 * Halyard's marketing team and their posts, for `wp ruvelo-editorial demo`.
 * Halyard is a made-up B2B billing platform. Maya Okafor runs marketing,
 * Inès Laurent edits, Tom Reyes and Kenji Mori write.
 *
 * Each post: [key, title, author, post type, body paragraphs, script].
 * The script is a list of [days ago, who, action, extra] steps run through
 * the plugin's own write path, so the history is real.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

return [
    'people' => [
        // name, role, job title
        ['Maya Okafor', 'administrator', 'Head of Marketing'],
        ['Inès Laurent', 'editor', 'Managing Editor'],
        ['Tom Reyes', 'author', 'Content Lead'],
        ['Kenji Mori', 'author', 'Product Marketing'],
    ],
    'posts' => [
        [
            'retries',
            'How Halyard retries failed payments',
            'Tom Reyes',
            'post',
            [
                '## Most failed payments aren’t lost customers',
                'One in eight card payments on a subscription fails at least once a year. Almost none of those customers meant to leave: a card expired, a bank flagged an unusual amount, or a limit was hit on the last day of the month.',
                '## Smart retries, on the customer’s calendar',
                'Halyard retries a failed payment up to four times over twelve days, at the hours that customer’s bank most often approves. Retries skip weekends for corporate cards and pause while a card update is pending.',
                '## What you see',
                'Every retry shows on the invoice timeline, with the bank’s decline code in plain words. When a payment recovers, the invoice is marked paid and the dunning emails stop on their own.',
            ],
            [
                [4.2, 'Tom Reyes', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => 2, 'message' => 'First draft of the retries explainer for the Payments launch. Mostly checking the numbers in the second section.']],
                [3.1, 'Maya Okafor', 'note', 'Can we say “up to four times” instead of a fixed number? Retries stop early when the card is updated.'],
                [3.0, 'Maya Okafor', 'note', 'Link “decline code” to the help article on decline reasons.'],
                [2.9, 'Maya Okafor', 'approve', 'Numbers check out with the Payments team. Approved once the decline-code link is in.'],
            ],
        ],
        [
            'dunning',
            'Five dunning emails that win customers back',
            'Tom Reyes',
            'post',
            [
                '## Dunning is a conversation, not a demand',
                'The first email after a failed payment sets the tone for every one after it. Ours are short, specific, and sent from a person.',
                '## 1. The heads-up',
                'Sent the day a payment fails: what happened, the amount, and one button to update the card. Nothing about late fees.',
                '## 2. The reminder',
                'Three days later, if nothing changed. It repeats the amount and links to the invoice.',
                '## 3–5. Escalate gently',
                'The last three emails add the account owner in copy, mention what happens on the due date, and finally offer a call.',
            ],
            [
                [6.0, 'Tom Reyes', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => -1, 'message' => 'Ready for a look. Templates are the ones we ship in the product.']],
                [5.2, 'Maya Okafor', 'approve', ''],
                [1.3, 'Inès Laurent', 'changes', ['message' => 'The structure works, but emails 3–5 are one paragraph for three emails. Give each its own section with the subject line we actually send, and drop “win customers back” from the title: it reads like they left.', 'note' => true]],
            ],
        ],
        [
            'pricing',
            'Pricing: what changed and why',
            'Maya Okafor',
            'page',
            [
                '## One price per invoice, not per seat',
                'From 1 November, Halyard charges per invoice sent, with the first 100 each month free. Seats are unlimited on every plan.',
                '## Why we changed it',
                'Finance teams told us seat pricing made them ration logins, so the people who needed to see invoices often couldn’t. Charging for invoices ties our price to the value you get.',
                '## What happens to your plan',
                'Nothing until your next renewal. If the new pricing is cheaper for you, you can switch today from Settings → Billing.',
            ],
            [
                [5.0, 'Maya Okafor', 'request', ['reviewers' => ['Inès Laurent'], 'due' => -2, 'message' => 'The pricing page copy for the November change. Legal has signed off on the numbers.']],
            ],
        ],
        [
            'northwind',
            'Northwind cut days sales outstanding by 18 days',
            'Tom Reyes',
            'post',
            [
                '## The customer',
                'Northwind Logistics sends 4,000 invoices a month to retailers across Europe, in five currencies.',
                '## The problem',
                'Their finance team chased late payments by hand from a spreadsheet. Average days sales outstanding (DSO) sat at 52 days.',
                '## What changed',
                'With automatic reminders, retries and a customer portal, Northwind’s DSO fell to 34 days in one quarter. “We stopped chasing and started forecasting,” says their CFO, Priya Nair.',
            ],
            [
                [8.0, 'Tom Reyes', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => 4, 'message' => '']],
                [6.5, 'Inès Laurent', 'changes', ['message' => 'Northwind asked to approve any quote from Priya before it goes out. Can you get her sign-off and add the date she approved it?', 'note' => false]],
                [0.6, 'Tom Reyes', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => 1, 'message' => 'Priya approved the quote on Tuesday (email in the shared drive). Ready again.']],
                [0.3, 'Maya Okafor', 'approve', 'The quote sign-off was all I needed. Approved.'],
            ],
        ],
        [
            'templates',
            'Invoice templates: a field guide',
            'Kenji Mori',
            'post',
            [
                '## Start from what your customers’ AP teams need',
                'A purchase order number, a clear due date, and bank details they can copy. Everything else is decoration.',
                '## Brand it once',
                'Upload your logo and pick an accent colour in Settings → Branding; every template follows.',
            ],
            [
                [2.0, 'Kenji Mori', 'request', ['reviewers' => ['Maya Okafor', 'Inès Laurent'], 'due' => 5, 'message' => 'Short one for the templates launch.']],
            ],
        ],
        [
            'usage',
            'Usage-based billing is here',
            'Kenji Mori',
            'post',
            [
                '## Bill for what customers use',
                'Send usage from your app through the API or a CSV upload, and Halyard turns it into invoice lines at the end of each billing period.',
                '## Tiers, volume and minimums',
                'Price usage per unit, in tiers or by volume, with optional monthly minimums and commitments. Customers see a running total in their portal.',
                '## Available today',
                'Usage-based billing is on every plan from today. Read the guide to send your first usage events.',
            ],
            [
                [3.5, 'Kenji Mori', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => 1, 'message' => 'Launch post for Thursday.']],
                [2.2, 'Inès Laurent', 'approve', 'Tightened the intro. Good to go.'],
                [1.8, 'Maya Okafor', 'approve', ''],
            ],
        ],
        [
            'changelog',
            'Changelog: September 2026',
            'Kenji Mori',
            'post',
            [
                '## New',
                'Usage-based billing, credit notes in bulk, and SEPA Direct Debit for customers in the euro area.',
                '## Improved',
                'Faster invoice search, and reminders that respect each customer’s time zone.',
            ],
            [
                [1.0, 'Kenji Mori', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => 2, 'message' => '']],
                [0.4, 'Maya Okafor', 'approve', ''],
                [0.2, 'Inès Laurent', 'approve', 'Ship it.'],
            ],
        ],
        [
            'webinar',
            'Webinar: closing the books in three days',
            'Kenji Mori',
            'post',
            [
                '## Join us on 12 November',
                'Maya Okafor and Northwind’s CFO talk through the month-end close they cut from nine days to three.',
            ],
            [],
        ],
        [
            'sepa',
            'Halyard now supports SEPA Direct Debit',
            'Kenji Mori',
            'post',
            [
                '## Collect euro payments from bank accounts',
                'Customers in the euro area can now pay by SEPA Direct Debit. They sign a mandate once in the portal, and Halyard collects every invoice on its due date.',
                '## Lower fees, fewer failures',
                'Bank debits cost less than cards and don’t expire, so fewer payments fail at renewal.',
            ],
            [
                [12.0, 'Kenji Mori', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => 2, 'message' => '']],
                [11.0, 'Inès Laurent', 'approve', ''],
                [10.8, 'Maya Okafor', 'approve', ''],
                [10.0, 'Maya Okafor', 'publish', ''],
            ],
        ],
        [
            'year',
            '2026 in review: what we shipped',
            'Tom Reyes',
            'post',
            [
                '## Twelve months, forty releases',
                'From usage-based billing to SEPA, here is everything that shipped this year, and what our customers did with it.',
            ],
            [
                [3.0, 'Tom Reyes', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => 1, 'message' => 'Scheduling for next week once approved.']],
                [2.4, 'Inès Laurent', 'approve', ''],
                [2.0, 'Maya Okafor', 'approve', ''],
                [1.9, 'Tom Reyes', 'schedule', 6],
            ],
        ],
        [
            'incident',
            'Incident report: delayed invoice emails on 3 October',
            'Tom Reyes',
            'post',
            [
                '## What happened',
                'Between 09:12 and 11:40 UTC on 3 October, invoice emails were delayed by up to two hours. No invoices were lost, and payment links worked throughout.',
                '## What we changed',
                'We moved email sending to a second provider that takes over automatically, and added an alert when the queue grows past five minutes.',
            ],
            [
                [6.0, 'Tom Reyes', 'request', ['reviewers' => ['Inès Laurent', 'Maya Okafor'], 'due' => 0, 'message' => 'Customers are asking. Can we get this out today?']],
                [5.9, 'Maya Okafor', 'override', 'Customers asked for this on the status page; Inès and I went through it on a call and she’s travelling.'],
            ],
        ],
    ],
];
