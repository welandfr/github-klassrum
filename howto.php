<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// Student guide. Static text; the screenshots in howto/ were taken from the app with example data.
sso_user();

$img = fn(string $file, string $alt) => '<figure><img src="' . h(url('howto/' . $file)) . '" alt="' . h($alt) . '" loading="lazy"></figure>';

page_start('Student guide');
echo '<p><a href="' . h(url()) . '">← My assignment repos</a></p>'
    . '<p>Klassrum gives you a <b>private GitHub repository</b> for each assignment, created from your teacher\'s template. '
    . 'You and your teammates can push to it, and your teachers can see it. Nobody else can.</p>'
    . '<p>It takes a few minutes, once per assignment:</p><ol>'
    . '<li><a href="#github">Connect your GitHub account</a> (only the first time)</li>'
    . '<li><a href="#team">Create or join a team</a>, or create your own repository for individual work</li>'
    . '<li><a href="#ready">Wait for your teammates</a> (team assignments only)</li>'
    . '<li><a href="#invite">Accept the invitation on GitHub</a></li>'
    . '</ol><p><a href="#problems">Problems?</a></p>';

echo '<h2>Before you start</h2>'
    . '<p>You need a <b>GitHub account</b>. A free account is enough. If you don\'t have one, create it at '
    . '<a href="https://github.com/signup">github.com/signup</a>. Use an account you will keep after your studies; '
    . 'any email address works.</p>'
    . '<p>Then open the <b>join link</b> your teacher gave you for the assignment. Log in with your Arcada account if asked.</p>';

echo '<h2 id="github">1. Connect your GitHub account</h2>'
    . '<p>The first time, Klassrum asks you to connect your GitHub account, so it knows whom to give access to the repository.</p>'
    . $img('connect.png', 'Join page with the Connect GitHub account button')
    . '<ol><li>Check that you are logged in to <b>your own</b> GitHub account in this browser (open github.com in another tab). '
    . 'Klassrum connects whichever account is logged in.</li>'
    . '<li>Click <b>Connect GitHub account</b>.</li>'
    . '<li>GitHub asks whether to authorize the app. Click <b>Authorize</b>. Klassrum only reads your GitHub username.</li>'
    . '<li>You come back to Klassrum, which shows <i>GitHub: your-username</i> under your Arcada username.</li></ol>'
    . '<p>You only do this once; later assignments use the same GitHub account.</p>';

echo '<h2 id="team">2. Create or join a team</h2>'
    . '<p><b>Agree with your teammates first</b>: one of you creates the team, and the others join it. '
    . 'Don\'t each create a team of your own.</p>'
    . '<h3>Create a team</h3>'
    . $img('create-team.png', 'Create a new team form with a team name field')
    . '<p>Give the team a name (optional) and click <b>Create team</b>. Two optional extras:</p><ul>'
    . '<li><b>Reserve slots for classmates</b>: enter the Arcada usernames of the classmates you agreed to work with. '
    . 'Only they can take those slots, so nobody else fills your team first. They still open the join link and click <b>Join</b> themselves.</li>'
    . '<li><b>Looking for teammates</b>: tick this if you don\'t know yet whom to work with. Your team is then highlighted in the list, '
    . 'so classmates without a team can find it.</li></ul>'
    . '<h3>Join a team</h3>'
    . $img('join-team.png', 'List of existing teams with Join buttons')
    . '<p>Find your team in the list and click <b>Join</b>. Teams <b>looking for teammates</b> are listed first: join one of them if you '
    . 'don\'t have a team yet. If a classmate reserved a slot for you, Klassrum tells you at the top of the page. '
    . 'A team that has reached the maximum size shows <b>Full</b>, and one whose free slots are reserved for others shows <b>Reserved</b>. '
    . 'If the team already has its repository, Klassrum asks you to confirm, because you can\'t leave such a team yourself.</p>'
    . '<h3>Individual assignments</h3>'
    . $img('individual.png', 'Your repository box with the Create my repository button')
    . '<p>For individual work there are no teams: click <b>Create my repository</b>, and it is created right away. '
    . 'Continue with <a href="#invite">step 4</a>.</p>';

echo '<h2 id="ready">3. Wait for your teammates</h2>'
    . $img('waiting.png', 'Team page showing the members, the join link and the Our team is ready button')
    . '<p>The repository is <b>not created yet</b>. Your teammates join by opening the same join link and clicking <b>Join</b> '
    . 'next to your team. Reload the page to see who has joined.</p>'
    . '<p>Under <b>Team settings</b>, any member can mark the team as looking for teammates (or stop), and reserve a free slot '
    . 'for a classmate. A reserved slot is listed with the members until that classmate joins; it can be removed again.</p>'
    . '<ul><li>When the team is <b>full</b>, the repository is created automatically. Reserved slots only count once '
    . 'the reserved classmates have actually joined.</li>'
    . '<li>If your team is complete with <b>fewer members</b> than the maximum, any member can click '
    . '<b>Our team is ready – create the repository</b> and confirm:</li></ul>'
    . $img('ready-dialog.png', 'Confirmation dialog: Your team has 1 of 2 members. Create the repository now?')
    . '<p>The repository is named after the assignment and the members\' Arcada usernames, in the order they joined. '
    . 'The name doesn\'t change later. Students who join afterwards still get access.</p>'
    . '<p>Joined the wrong team? Click <b>Leave team</b>. This works until the repository has been created; after that, ask your teacher.</p>';

echo '<h2 id="invite">4. Accept the invitation on GitHub</h2>'
    . $img('repo-created.png', 'Team page with the repository link and the Accept your invitation link')
    . '<p>When the repository is created, every member gets a <b>GitHub invitation</b>:</p>'
    . '<ol><li>Click the <b>Accept your invitation</b> link (it ends in <code>/invitations</code>). '
    . 'GitHub usually also sends the invitation by email.</li>'
    . '<li>On GitHub, click <b>Accept invitation</b>.</li>'
    . '<li>Open the repository link. You can now clone it and push to it.</li></ol>'
    . '<p>Until you accept, the repository shows GitHub\'s <b>“404 – page not found”</b>. That is normal for a private repository you don\'t have access to yet. '
    . 'Invitations expire after 7 days; if yours has expired, ask your teacher to send a new one.</p>';

echo '<h2>Finding your repositories later</h2>'
    . $img('my-repos.png', 'My assignment repos page listing an assignment, its team and repository')
    . '<p>The <a href="' . h(url()) . '">start page</a> lists all your assignments, teams and repositories, '
    . 'with the invitation link while you still need it.</p>';

echo '<h2 id="problems">Problems</h2><dl>'
    . '<dt><b>I connected the wrong GitHub account.</b></dt>'
    . '<dd>On the <a href="' . h(url()) . '">start page</a>, log in to the right account on github.com first, then click <b>re-link</b>. '
    . 'If you are already in a team with a repository, also tell your teacher: the invitation was sent to the old account.</dd>'
    . '<dt><b>“GitHub account … is already linked to another university user.”</b></dt>'
    . '<dd>Each GitHub account can be connected to one Arcada user only. You are probably logged in to someone else\'s GitHub account; '
    . 'log out on github.com and log in to your own.</dd>'
    . '<dt><b>The repository shows 404 on GitHub.</b></dt>'
    . '<dd>Accept the invitation first (step 4), and check that you are logged in to the GitHub account shown in Klassrum.</dd>'
    . '<dt><b>“Your GitHub invitation could not be sent.”</b></dt>'
    . '<dd>Contact your teacher, who can send it again.</dd>'
    . '<dt><b>I am in the wrong team.</b></dt>'
    . '<dd>Click <b>Leave team</b> while the team has no repository yet. Otherwise, ask your teacher to move you.</dd>'
    . '<dt><b>“This assignment is closed.”</b></dt>'
    . '<dd>The teacher has stopped new teams and joins. Contact your teacher.</dd>'
    . '</dl>';
page_end();
