# Quranic Quiz (Tamil) — Install Guide

Bismillah. This guide gets the Tamil quiz live on **kadavulmattum.org** in
about 5-10 minutes. This is a brand-new, separate plugin install — it does
not touch or depend on the English plugin already running on godalone.in.

## 1. Install the plugin

1. Log in to the kadavulmattum.org WordPress admin
   (`https://kadavulmattum.org/wp-admin/`).
2. Go to **Plugins → Add New → Upload Plugin**.
3. Choose the file `quranic-quiz-tamil.zip` and click **Install Now**, then
   **Activate**.
4. A new **Quranic Quiz** menu appears in the left sidebar. Open it — you
   should see **427 multiple-choice + 44 True/False questions (471 total)**
   already loaded on the **Questions** tab, imported automatically from your
   Tamil Excel file the first time the plugin activates. Breakdown by
   difficulty: 162 Easy, 206 Medium, 103 Hard.

No Google Cloud / OAuth setup is needed. Visitors just type their name to
start — no account or password required, exactly like the godalone.in site.

## 2. Create the /quran-qa/ page

`https://kadavulmattum.org/quran-qa/` does not exist yet (it currently shows
"Page not found"), so this is a fresh page, not an edit.

1. In wp-admin, go to **Pages → Add New**.
2. Title it something like "குர்ஆன் கேள்வி பதில்" (Quran Q&A).
3. **Important:** set the page **slug/permalink** to exactly `quran-qa`
   (edit it in the URL box under the title) so it matches
   `kadavulmattum.org/quran-qa/`.
4. Add a **Shortcode** block (or **Custom HTML** block) containing exactly:

   ```
   [quranic_quiz]
   ```

5. Publish the page. Visit `https://kadavulmattum.org/quran-qa/` — you
   should see the Tamil "ஆரம்பிக்கலாம், இன்ஷா அல்லாஹ்" sign-in screen.

## 3. Fix the existing menu link

The site's main navigation already has a "குர்ஆன் கேள்வி பதில்" (Quran Q&A)
menu item, but right now it incorrectly points to godalone.in instead of
this new page. To fix it:

1. Go to **Appearance → Menus** (or **Appearance → Editor → Navigation** on
   block themes).
2. Find the "குர்ஆன் கேள்வி பதில்" menu item.
3. Edit its URL/link so it points to `https://kadavulmattum.org/quran-qa/`
   (your own new page from step 2) instead of the godalone.in link.
4. Save/update the menu.

## 4. Try it end-to-end

1. Visit the page, type a name, start a quiz.
2. Pick a difficulty (எளிது/நடுத்தரம்/கடினம்/கலவை), answer a few questions,
   submit.
3. Confirm the score screen, the "சான்றிதழைப் பதிவிறக்கு" (certificate PDF)
   download, and "சான்றிதழைப் பகிர்" (share as image) all work.
4. Try "மீண்டும் முயற்சி" (Try Again) — confirm you can retake the quiz as
   many times as you like, same as godalone.in.

## 5. Cache

Just like on godalone.in, this plugin auto-busts the cache on every file
re-upload (the CSS/JS version tag is based on the file's own last-modified
time), so you generally don't need to manually clear anything after
updating a file here. If you ever do see an old version, a normal
browser hard-refresh (Ctrl+Shift+R / Cmd+Shift+R) is enough.

## Adding or fixing questions later

Go to **Quranic Quiz → Add Question** to add a single question by hand
(4 options + difficulty), or **Quranic Quiz → Import Questions** to bulk
add/replace from a CSV. Download the **CSV template** button on that page
for the exact column headers — Tamil text works the same as English there,
just type it directly into the CSV/Excel cells.

Rows with a missing option, an unrecognised difficulty, or a correct answer
that doesn't match a real option are **skipped and listed on screen** —
nothing is ever guessed or invented.

## Notes on data & privacy

- Each visitor's quiz history is private to their own browser session.
- No account, password, or Google sign-in is required to take the quiz.
- Uninstalling the plugin normally **keeps** all questions and quiz history
  in the database (in case you reinstall). To fully wipe everything, see the
  comment at the top of `uninstall.php`.

May Allah accept this effort and make it a means of benefit for the Tamil
community too. Ameen.
