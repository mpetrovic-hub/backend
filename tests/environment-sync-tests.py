"""Functional checks against disposable local Git remotes; no GitHub writes."""

from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "tools/environment/sync-main.sh"


def git(path, *args):
    return subprocess.check_output(["git", "-C", str(path), *args], text=True, stderr=subprocess.DEVNULL).strip()


class EnvironmentSyncTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="kiwi-environment-sync-")
        root = Path(self.temp.name)
        self.remote = root / "remote.git"
        self.upstream = root / "upstream"
        self.checkout = root / "checkout"
        subprocess.run(["git", "init", "--bare", "--initial-branch=main", str(self.remote)], check=True, capture_output=True)
        subprocess.run(["git", "clone", str(self.remote), str(self.upstream)], check=True, capture_output=True)
        for path in [self.upstream]:
            git(path, "config", "user.name", "Sandbox")
            git(path, "config", "user.email", "sandbox@example.invalid")
        (self.upstream / "tracked.txt").write_text("initial\n")
        git(self.upstream, "add", ".")
        git(self.upstream, "commit", "-m", "initial")
        git(self.upstream, "push", "origin", "main")
        subprocess.run(["git", "clone", str(self.remote), str(self.checkout)], check=True, capture_output=True)
        git(self.checkout, "checkout", "-b", "work")
        git(self.checkout, "config", "user.name", "Sandbox")
        git(self.checkout, "config", "user.email", "sandbox@example.invalid")
        self.initial = git(self.checkout, "rev-parse", "HEAD")

    def tearDown(self):
        self.temp.cleanup()

    def advance_remote(self):
        (self.upstream / "tracked.txt").write_text("new upstream\n")
        git(self.upstream, "add", ".")
        git(self.upstream, "commit", "-m", "upstream advance")
        git(self.upstream, "push", "origin", "main")
        return git(self.upstream, "rev-parse", "HEAD")

    def run_sync(self, mode="--update"):
        return subprocess.run(["bash", str(SCRIPT), mode, "--repo", str(self.checkout)], text=True, capture_output=True)

    def test_clean_checkout_fast_forwards_without_switching_work_branch(self):
        target = self.advance_remote()
        result = self.run_sync()
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertIn("status=updated", result.stdout)
        self.assertEqual(git(self.checkout, "rev-parse", "HEAD"), target)
        self.assertEqual(git(self.checkout, "branch", "--show-current"), "work")
        self.assertEqual((self.checkout / "tracked.txt").read_text(), "new upstream\n")

    def test_check_fetches_without_changing_checkout(self):
        target = self.advance_remote()
        result = self.run_sync("--check")
        self.assertEqual(result.returncode, 0)
        self.assertIn("status=differs", result.stdout)
        self.assertEqual(git(self.checkout, "rev-parse", "origin/main"), target)
        self.assertEqual(git(self.checkout, "rev-parse", "HEAD"), self.initial)
        self.assertEqual((self.checkout / "tracked.txt").read_text(), "initial\n")

    def test_tracked_changes_are_preserved(self):
        self.advance_remote()
        (self.checkout / "tracked.txt").write_text("unfinished work\n")
        result = self.run_sync()
        self.assertEqual(result.returncode, 2)
        self.assertIn("reason=local_changes", result.stdout)
        self.assertEqual(git(self.checkout, "rev-parse", "HEAD"), self.initial)
        self.assertEqual((self.checkout / "tracked.txt").read_text(), "unfinished work\n")

    def test_untracked_prototype_is_preserved(self):
        self.advance_remote()
        (self.checkout / "prototype.txt").write_text("preserve prototype\n")
        result = self.run_sync()
        self.assertEqual(result.returncode, 2)
        self.assertIn("reason=local_changes", result.stdout)
        self.assertEqual(git(self.checkout, "rev-parse", "HEAD"), self.initial)
        self.assertEqual((self.checkout / "prototype.txt").read_text(), "preserve prototype\n")

    def test_own_commit_is_not_merged_or_rebased(self):
        (self.checkout / "own.txt").write_text("local commit\n")
        git(self.checkout, "add", ".")
        git(self.checkout, "commit", "-m", "local work")
        own_head = git(self.checkout, "rev-parse", "HEAD")
        self.advance_remote()
        result = self.run_sync()
        self.assertEqual(result.returncode, 2)
        self.assertIn("reason=local_commits", result.stdout)
        self.assertEqual(git(self.checkout, "rev-parse", "HEAD"), own_head)

    def test_detached_checkout_is_not_updated(self):
        self.advance_remote()
        git(self.checkout, "checkout", "--detach")
        result = self.run_sync()
        self.assertEqual(result.returncode, 2)
        self.assertIn("reason=detached_head", result.stdout)
        self.assertEqual(git(self.checkout, "rev-parse", "HEAD"), self.initial)

    def test_fetch_failure_preserves_checkout(self):
        git(self.checkout, "remote", "set-url", "origin", str(self.remote) + ".missing")
        result = self.run_sync()
        self.assertEqual(result.returncode, 1)
        self.assertIn("reason=fetch_failed", result.stdout)
        self.assertEqual(git(self.checkout, "rev-parse", "HEAD"), self.initial)
        self.assertEqual((self.checkout / "tracked.txt").read_text(), "initial\n")

    def test_current_commit_does_not_discard_dirty_files(self):
        (self.checkout / "tracked.txt").write_text("local prototype\n")
        result = self.run_sync()
        self.assertEqual(result.returncode, 0)
        self.assertIn("status=current", result.stdout)
        self.assertIn("working_tree_dirty=true", result.stdout)
        self.assertEqual((self.checkout / "tracked.txt").read_text(), "local prototype\n")

    def test_in_progress_git_operation_is_not_interrupted(self):
        self.advance_remote()
        marker = Path(git(self.checkout, "rev-parse", "--path-format=absolute", "--git-path", "rebase-merge"))
        marker.mkdir()
        result = self.run_sync()
        self.assertEqual(result.returncode, 2)
        self.assertIn("reason=git_operation_in_progress", result.stdout)
        self.assertEqual(git(self.checkout, "rev-parse", "HEAD"), self.initial)


if __name__ == "__main__":
    unittest.main(verbosity=2)
