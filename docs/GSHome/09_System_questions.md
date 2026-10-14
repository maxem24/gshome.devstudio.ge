---
tags: [gshome, questions]
---

# Questions about how the system should work

Compared on 2026-10-11: the client spec (`01_ТЗ.md`) against the code and the decisions log (`02_Решения.md`).

This file is only what the spec does not settle. Each question is something the next screens (notes, status, meetings, team control) cannot be built without an answer. Already decided points are listed at the end and are not asked again.

## Where the project is

| Spec area | State |
|---|---|
| Companies, four roles, teams, phones, exchange pairs (sections 3, 4.7, 5.6, 5.7) | Built. People are created by the group owner only. A phone belongs to a company; an employee is the current holder. |
| Listings list, grid, card, filters, direction, ownership label (sections 4.1–4.5, 5.7) | Built as a live read from the Real Estate API. No copy of the listings database in GS Home. |
| Seller category on the list | Built as three values from the source: owner, agency, agent. The fourth spec value, "other agency", is not a separate switch. |
| "Contact" on our employee | Built as a dialog with the holder's name and phone (`tel:` link). |
| Collection, normalization, duplicate clusters (sections 5.1–5.3, 5.5) | Not in GS Home. Real Estate already does this. The spec's "per user" duplicate check is postponed. |
| Object status, internal notes, change history on the card | Not built. |
| Meetings, five results, reminders (sections 4.6, 4.8, 5.4) | Not built. |
| Team-lead screen: activity, their listings, meetings, progress (section 4.7) | Not built. A team lead cannot open the people screens; those are owner and company-owner only. |

## 1. What the list shows by default

The spec says an employee works "their listings, their search, their meetings" (5.6), and also that after login they see the objects of their direction (4.1), with a filter by employee (4.5).

1. Is the default list the whole market of that direction (our companies and outside companies together), with "employee" only as a narrowing filter?
2. For a company owner, section 4.7 says they see the objects of their company, while section 4.1 does not give them a separate entry screen. Is their default list their company's listings only, or the same whole market, with both directions?

## 2. Who leads the object

Ownership is a phone match (decision of 2026-10-09). The spec still says the card shows "the current employee, if the listing is ours" (4.4) and that colleagues use Contact to agree whether the object should be aimed at sale or at monthly rent (4.4).

3. A direction is a property of the source listing, and the other team never receives that listing (5.6, 6). How can two colleagues agree to switch an object from sale to rent if each of them is blind to the other direction?
4. If the matched phone is an office number, whose name is shown, and is that one person treated as leading every listing on that number?
5. If the holder was archived and the number has no holder, the card can show the company and the number without a name. Is that the right card, or must a listing with no holder stay "other company"?
6. When a number is given to another employee, listings start showing the new holder immediately. Do meetings and notes stay with the person who created them? Should the card keep any trace of the previous holder?
7. The same listing can show the platform's seller phone and, separately, our employee's phone. When those are the same number, should the card show it once or twice? Is the platform seller phone visible to every role, including on "other company"?
8. myhome.ge often stores a masked phone. That listing cannot be tied to our employee and shows as "other company". Is a manual "this listing is ours" action allowed, or does the system stay phone-only even when the match is impossible?
9. A duplicate group often keeps the owner's copy as the main listing, so our agent's copy is hidden inside the group. The list then shows "other company" plus a second label "our employee is in this group". Is that the label agents should trust when deciding who leads the object?

## 3. Five buttons above the list

Built on 2026-10-14. The buttons sit above the collapsed filter. They narrow the list the person already sees. They are not a deal direction. The collapsed filter still has "owners only", "ours / other companies", and "employee".

| Button | What it shows |
|---|---|
| All | Every listing of the allowed direction. The ownership badge stays. |
| Owners | `is_owner=1`. The seller on the source is an owner. |
| Agencies | The seller on the source is an agency, including agencies that are not ours. The API has no such parameter, so the screen keeps those rows and reads at most eight pages per step. The listing assembler should accept `is_agency` and should send each cluster member's seller fields on the list row. That cuts the extra page reads and the follow-up card reads. See `07_Требования_к_API_Real_Estate.md`, R8 and R9. |
| Our agency | Phones of the signed-in person's company. Linked companies are not included. The group owner has no company, so this button uses every active company. |
| My listings | Phones held by the signed-in person, not the whole agency. |

A company administrator sees that company's name in the account menu and above the list. When exchange between two companies is on, an employee of one sees "Same owner" on the other's listing and, on a separate line, the name of the company that listing belongs to. A duplicate group that contains an owner copy and our side says both that this company has the listing and that the owner still has another listing on the site.

10. Closed. For the group owner, "Our agency" is every active company.
11. Closed. "My listings" is the signed-in person's phone. A listing with no matching phone is not included.

## 4. Duplicates

Section 5.3 says the duplicate check runs inside each user's own data, not as one check for the whole agency. Section 6 also says roles do not cancel that per-user check. The working system uses Real Estate clusters, which are global. This is still open in the decisions log.

13. What is "this user's data" if the user did not create the listing? Every imported listing of their direction, or only listings tied to their phone?
14. If agent A hides a full duplicate or dismisses a "possible duplicate", does agent B still see the duplicate? Is the decision personal, company-wide, or global?
15. Who is allowed to confirm or dismiss a possible duplicate: the agent, their team lead, the company owner, the group owner?
16. Step 4 of the import says "update statistics". Which statistics, and who sees them?
17. A meeting or a note is attached to one listing, and that listing is later merged into another. Does the meeting move to the surviving card, stay on the hidden one, or appear on both?

## 5. Status of an object

The list, the card, and the filters all have a "status" (4.3, 4.4, 4.5). The only statuses the spec defines are the five meeting results (4.6). Section 6 says the meeting result is the deal status for that object and that client.

18. Is the object's status a CRM pipeline (for example new, in progress, reserved, sold, rented), the five meeting results, or the source listing's active/removed state?
19. What is the full list of values, and which of them can be chosen by hand, without a meeting?
20. If two agents have meetings on the same object, which status is "the current status" on the card and in the filter?
21. When the result is "deal confirmed", does the object leave the working list for everyone, or only stop being active for that agent?

## 6. Internal notes

The card has internal notes that are not the public description (4.4). The spec does not say who writes them or who reads them.

22. Is a note personal, visible to the team lead, visible to the whole company, or visible to everyone who can open the card?
23. Can a company owner and the group owner read notes of people in the company?
24. Does an agent see a colleague's note on a shared listing?
25. After the author is archived, do their notes stay on the card, and under whose name?

## 7. Meetings and the client

A meeting has a date, a time, a client name, an object, and notes (4.6). It is stored as a record linked to a client and an object (5.4). An employee works "their meetings" (5.6). A team lead sees the meetings of their own employees (4.7). The group owner sees all meetings (5.6). The company owner is not mentioned.

26. Is the client only a name typed on the meeting, or a separate record with phone, history, and later meetings?
27. If two meetings use the same client name, are they the same client? Can two agents share one client, or does each agent have a private list?
28. Can an agent create a meeting on any listing of their direction, including "other company" and a colleague's listing?
29. Who sees that meeting: only the author, the author and their team lead, the whole company, or anyone who opens the card?
30. Should the card tell other agents that someone already has a meeting on this object, even if the meeting itself stays hidden?
31. Can a meeting be edited, rescheduled, or cancelled, and by whom?
32. The company owner sees the people and objects of their company (4.7). Do they also see those people's meetings?
33. A person with access to both directions still has one team lead, in one direction (5.6). Who supervises the meetings they create in the other direction?

## 8. Reminders

The user gets a reminder before the meeting, a notice on the day of the meeting, and a request to set the result if the meeting time has passed and the result is empty (4.8, 5.4). Channels are not set; the working assumption in the decisions log is Filament notifications and email, and that assumption is still open.

34. How long before the meeting does the first reminder fire, and at what time does the day-of notice fire?
35. After the meeting time, how soon is the "set the result" reminder sent, how often is it repeated, and when does it stop?
36. Who receives each of the three: only the agent who owns the meeting, or also their team lead?
37. Does a blocked user still receive them? An archived user?

## 9. Team lead control

Section 4.7 says the team lead sees their employees' activity, listings, meetings, statuses, and progress, and does not see another team lead's employees. "Activity" is not defined. The goal in section 2 says "control of teams" without saying what is measured.

38. What counts as activity: logins, listings opened, meetings created, results filled, notes written, or something else?
39. Which numbers must the team lead see (meetings held, results still empty, deals confirmed), and is the same screen required for the company owner and the group owner?
40. "Their listings" for a team lead: listings whose phone is held by their employees, or every listing those employees have a meeting or a note on?
41. Agents of the same company under different team leads see each other as colleagues on a listing (4.4: "colleague of their company"). The team lead does not see the other group (4.7). Is that split intentional: contact on the card is company-wide, supervision is only the team lead's own group?

## 10. Companies, hiring, and exchange

The spec says the group owner maintains the company list and the exchange switch (4.7, 5.6). It says the company owner does not edit that list. It does not say who creates employees.

42. Can a company owner add, move, or archive people inside their company, or is every hire done by the group owner, as the system does now?
43. Can a direction have several team leads, as in the diagram in section 3, and can a company exist with a company owner and no team yet?
44. Exchange is described as visibility of the other company's employee on the listing (5.7). Does "on" reveal anything else: their meetings, notes, the employee list, or only the name, company, and Contact action?
45. Is turning the exchange off immediate for cards already open, and does it hide that employee everywhere at once?

## 11. What "Contact" does

Section 5.7 says no delivery channel is fixed in this version; the card only has the action, so the sale-or-rent question can be closed with that person. The current dialog shows the name and a `tel:` link. That is an implementation choice, not a spec answer.

46. In the finished system, what must Contact do: show the phone, start a call, open WhatsApp or Viber, or create an internal message inside GS Home?
47. Is Contact available only to people who are allowed to see the name, and is it available on an office number that has no holder?

## 12. Listings that change or disappear

GS Home does not store the listing. Real Estate can drop it, change the price, or change the deal type.

48. A meeting or a note points at a listing that Real Estate no longer returns. What does the user see: a stub with the old title, a dead link, or is the meeting blocked from creation unless the listing is still active?
49. The source changes the listing from sale to monthly rent. Do existing meetings and notes follow it into the other direction, stay visible to the original team, or become invisible to both?
50. Daily rent and pledge exist on the sources. The spec only has sale and monthly rent. Do we keep dropping daily rent and pledge, as the list does now?

## 13. History, money, language, sources

The card includes a change history, filled on import, on data updates, and on duplicate operations (4.4, 5.5). The spec does not say which events. Section 8 leaves channels, sources, and visual design unset.

51. Which history does the agent see: source changes (price, photos, text), CRM changes (notes, status, meetings), or both? Are duplicate merges part of that same history?
52. Which price is the working price: USD, GEL, or both, and is "per month" always the rental amount in the same currency?
53. Are myhome.ge and ss.ge the full set of sources for this version?

## Already decided — not asked again

- Listings are not copied into GS Home; each screen is a live API request (2026-10-10).
- One group of companies, not a multi-tenant product (2026-10-09).
- Roles are fixed. A person sits in one company and one team. Transfers have no history (2026-10-09).
- Exchange can be any number of company pairs, not a single pair (2026-10-09).
- People have three statuses: active, blocked, archived. Archive hands phones and employees over; returning from archive does not return phones (2026-10-10).
- "Other companies" on the filter excludes only phones the viewer is allowed to identify, not every phone in the group (2026-10-11).
- Duplicate groups use two labels: one from the listing's own phone, and a second if some other phone in the group is ours (2026-10-10).
- The interface is Russian, English, or Georgian, saved on the person and switched from the account menu. Listing text uses the same locale and falls back to Georgian (2026-10-13).
