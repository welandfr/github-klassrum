<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_teacher();

// Teacher guide. Static text; the screenshots in howto/ were taken from the app with example data, and from GitHub.
$img = fn(string $file, string $alt) => '<figure><img src="' . h(url('howto/' . $file)) . '" alt="' . h($alt) . '" loading="lazy"></figure>';
$appUrl = setting('gh_client_id') ? gh_app_url() : null;
$install = $appUrl
    ? '<a href="' . h($appUrl . '/installations/new') . '">' . h($appUrl . '/installations/new') . '</a>'
    : 'the Klassrum app\'s page on GitHub (ask a main admin for the link)';

page_start('Teacher guide');
echo '<p><a href="' . h(url('admin/')) . '">← My assignments</a></p>'
    . '<p>With Klassrum, each student or team gets a <b>private GitHub repository</b> for an assignment, created from your template repository. '
    . 'You see your own assignments and the students in them; other teachers have their own.</p>'
    . '<p>Once per student group (e.g. a class starting in 2027):</p><ol>'
    . '<li><a href="#github">Connect your GitHub account</a> (only the first time)</li>'
    . '<li><a href="#org">Create a GitHub organization</a> for the group</li>'
    . '<li><a href="#install">Install the Klassrum app</a> on the organization</li>'
    . '<li><a href="#settings">Adjust the organization\'s settings</a></li></ol>'
    . '<p>For each assignment:</p><ol start="5">'
    . '<li><a href="#template">Prepare a template repository</a></li>'
    . '<li><a href="#create">Create the assignment</a></li>'
    . '<li><a href="#share">Share the join link</a></li>'
    . '<li><a href="#follow">Follow the teams</a></li></ol>'
    . '<p><a href="#problems">Problems?</a> · Students have their own <a href="' . h(url('howto.php')) . '">student guide</a>.</p>';

echo '<h2 id="github">1. Connect your GitHub account</h2>'
    . '<p>Klassrum only lets you use organizations that your GitHub account owns, so it needs to know your account.</p><ol>'
    . '<li>Log in to github.com with your own account in this browser.</li>'
    . '<li>Open <a href="' . h(url('admin/assignment.php')) . '">New assignment</a> and click <b>Connect GitHub account</b>, then <b>Authorize</b> on GitHub.</li>'
    . '</ol><p>Your GitHub username is then shown under the organization field.</p>';

echo '<h2 id="org">2. Create a GitHub organization for the group</h2>'
    . '<p>Each student group gets its own organization, e.g. <code>it-27-en</code>. The students\' repositories are created there, '
    . 'and you, as its owner, can see all of them. Students don\'t become members; they only get access to their own repositories.'
    . '</p>' . $img('github-new-org.png', 'GitHub form Set up your organization, with organization name, contact email and owner') . '<ol>'
    . '<li>On GitHub, open <a href="https://github.com/account/organizations/new?plan=free">github.com/account/organizations/new</a> '
    . '(your profile picture → <i>Organizations</i> → <i>New organization</i>) and choose the <b>Free</b> plan.</li>'
    . '<li>Enter the organization name and your email, and choose <i>My personal account</i> as the owner.</li>'
    . '<li>Skip adding members. Other teachers who should see all repositories can be added later as <b>owners</b> '
    . '(<i>People → Invite member</i>, role <i>Owner</i>).</li></ol>'
    . '<p>The free plan is enough: it allows unlimited private repositories.</p>';

echo '<h2 id="install">3. Install the Klassrum app on the organization</h2>'
    . '<p>The app creates the repositories and invites the students. It only acts in organizations where it is installed.</p><ol>'
    . '<li>Open ' . $install . '.</li>'
    . '<li>Choose your new organization. You must be its owner; otherwise GitHub only sends an install request to the owners.'
    . $img('github-install-select.png', 'GitHub page Install klassrum: where do you want to install it, with a list of accounts and organizations') . '</li>'
    . '<li>Keep <b>All repositories</b> selected and click <b>Install</b>.'
    . $img('github-install.png', 'GitHub install page with All repositories selected and the Install button') . '</li>'
    . '<li>The organization now appears in the organization list of the <a href="' . h(url('admin/assignment.php')) . '">New assignment</a> form.</li></ol>'
    . '<p>If the app later needs more permissions, GitHub emails the organization\'s owners. Accept the request '
    . '(or under the organization\'s <i>Settings → GitHub Apps</i>), or parts of Klassrum stop working.</p>';

echo '<h2 id="settings">4. Adjust the organization\'s settings</h2>'
    . '<p>In the organization on GitHub, open <i>Settings → Member privileges</i>:</p>'
    . $img('github-member-privileges.png', 'GitHub Member privileges: Base permissions No permission, repository creation Public and Private unticked') . '<ul>'
    . '<li><b>Base permissions: No permission.</b> Otherwise every organization member can see every student repository.</li>'
    . '<li><b>Repository creation:</b> untick <i>Public</i> and <i>Private</i> and click <i>Save</i>, so only owners and the app create repositories.</li></ul>'
    . '<p>Klassrum warns when you save an assignment in an organization whose base permission isn\'t <i>No permission</i>.</p>';

echo '<h2 id="template">5. Prepare a template repository</h2>'
    . '<p>Every student repository starts as a copy of a template repository: starter code, instructions, tests.</p><ol>'
    . '<li>Create the repository on GitHub, preferably as a <b>private</b> repository in the group\'s organization. '
    . 'A public repository anywhere on GitHub works too.</li>'
    . '<li>In the repository, open <i>Settings → General</i> and tick <b>Template repository</b>.'
    . $img('github-template.png', 'GitHub repository settings with the Template repository checkbox ticked') . '</li></ol>'
    . '<p>Students get the template as it is when their repository is created. Later changes to the template don\'t reach existing repositories.</p>';

echo '<h2 id="create">6. Create the assignment</h2>'
    . '<p>On <a href="' . h(url('admin/')) . '">My assignments</a>, click <b>+ New assignment</b>:</p>'
    . $img('teacher-new-assignment.png', 'New assignment form with organization, title, slug, template repository and max team size')
    . '<ul><li><b>GitHub organization:</b> the group\'s organization. Only organizations you own where the app is installed are listed.</li>'
    . '<li><b>Title:</b> shown to the students.</li>'
    . '<li><b>Slug:</b> the start of the repository names. Repositories are named after the slug and the members\' Arcada usernames, '
    . 'e.g. <code>webprog-p1-anna-ben</code>.</li>'
    . '<li><b>Template repository:</b> <code>owner/name</code> or the repository\'s GitHub address.</li>'
    . '<li><b>Max team size:</b> 1 for individual work. For teams, the repository is created when the team is full, '
    . 'or earlier when a member clicks <i>Our team is ready</i>.</li>'
    . '<li><b>Open for new teams and joins:</b> untick it to close the assignment, e.g. after the deadline. Existing repositories are not affected.</li></ul>'
    . '<p>The same assignment for another group: open it and click <b>Copy to a new assignment</b> (or <b>Duplicate</b> in the list), '
    . 'choose the other organization and save. Each assignment has its own join link.</p>';

echo '<h2 id="share">7. Share the join link</h2>'
    . $img('teacher-join-link.png', 'Assignment page with the join link for students')
    . '<p>Give the students the assignment\'s <b>join link</b>, e.g. in your course page. <b>Copy join link</b> in the assignment list copies it. '
    . 'Students log in with their Arcada account, connect their GitHub account, and create or join a team. '
    . 'The <a href="' . h(url('howto.php')) . '">student guide</a> explains their steps; you can link it too.</p>';

echo '<h2 id="follow">8. Follow the teams</h2>'
    . $img('teacher-assignments.png', 'My assignments list grouped by organization, with team and student counts')
    . '<p><a href="' . h(url('admin/')) . '">My assignments</a> lists your assignments by organization, with the number of teams and students, '
    . 'and failed invitations. Open an assignment to see its teams:</p>'
    . $img('teacher-teams.png', 'Teams table with a team waiting for members, teams with repositories, a failed invitation and a Changed on GitHub box')
    . '<ul><li><b>waiting for members:</b> the repository isn\'t created yet. <b>Create repo</b> creates it now, e.g. when a team stays incomplete. '
    . '<i>Looking</i> marks teams that look for teammates.</li>'
    . '<li><b>reserved, not joined:</b> a member reserved the slot for a classmate. <b>unreserve</b> frees it.</li>'
    . '<li><b>invite failed:</b> GitHub refused the invitation (hover for the reason). <b>Retry failed invitations</b> tries again.</li>'
    . '<li><b>remove</b> takes a student out of the team and removes their access on GitHub. They can then join another team. '
    . 'For a team\'s last member you can also delete the repository.</li>'
    . '<li><b>Delete team</b> only removes the team from Klassrum; the repository stays on GitHub.</li>'
    . '<li><b>Changed on GitHub</b> appears when something was changed directly on GitHub, e.g. a repository was deleted or a collaborator removed. '
    . '<b>Update the app to match</b> makes Klassrum follow.</li></ul>'
    . '<p>Under the assignments, <b>Students</b> lists the students in your assignments and their GitHub accounts. <b>unlink</b> disconnects a '
    . 'wrongly connected GitHub account, so the student can connect the right one. It is disabled when the student is also in another '
    . 'teacher\'s assignment; ask a main admin then.</p>';

echo '<h2 id="problems">Problems</h2><dl>'
    . '<dt><b>My organization isn\'t in the list.</b></dt>'
    . '<dd>Check that your GitHub account is connected (the username under the field), that it is an <b>owner</b> of the organization, '
    . 'that the app is installed on it (<a href="#install">step 3</a>), and that the organization has accepted the app\'s permissions. '
    . 'Then reload the page.</dd>'
    . '<dt><b>“Template repo … is not accessible to the GitHub App.”</b></dt>'
    . '<dd>The template must be public, or private in the assignment\'s organization.</dd>'
    . '<dt><b>“… is not marked as a template repository.”</b></dt>'
    . '<dd>Tick <i>Template repository</i> in the repository\'s settings (<a href="#template">step 5</a>).</dd>'
    . '<dt><b>A student\'s invitation failed.</b></dt>'
    . '<dd>Hover over <i>invite failed</i> for GitHub\'s reason. If the student connected the wrong GitHub account, unlink it, let them connect the right one, '
    . 'then remove them from the team and let them join again. Otherwise fix the cause and click <b>Retry failed invitations</b>.</dd>'
    . '<dt><b>A student sees “404” on the repository.</b></dt>'
    . '<dd>They haven\'t accepted the invitation yet, or they are logged in to another GitHub account. Invitations expire after 7 days; '
    . '<b>Changed on GitHub</b> then offers to update the app, and <b>Retry failed invitations</b> sends a new one.</dd>'
    . '<dt><b>A student is in the wrong team.</b></dt>'
    . '<dd>Before the repository exists, they can leave the team themselves. Afterwards, <b>remove</b> them, and they can join another team.</dd>'
    . '</dl>';
page_end();
