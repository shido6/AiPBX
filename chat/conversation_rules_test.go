package main

import (
	"encoding/json"
	"fmt"
	"strings"
	"testing"
	"time"
	"unicode/utf8"
)

func TestTruncateRunesKeepsValidUTF8(t *testing.T) {
	long := strings.Repeat("ş", 300)
	got := truncateRunes(long, 250)
	if !utf8.ValidString(got) {
		t.Fatal("truncateRunes produced invalid UTF-8")
	}
	if n := utf8.RuneCountInString(got); n != 250 {
		t.Fatalf("want 250 characters, got %d", n)
	}
	if !strings.HasSuffix(got, "...") {
		t.Fatal("a cut preview must end with ...")
	}
	if got := truncateRunes("kısa", 250); got != "kısa" {
		t.Fatalf("short text must stay unchanged, got %q", got)
	}
	if got := clipRunes(strings.Repeat("ğ", 120), 100); !utf8.ValidString(got) || utf8.RuneCountInString(got) != 100 {
		t.Fatalf("clipRunes: want 100 valid characters, got %q", got)
	}
}

func TestSystemMetaEncodesUserInput(t *testing.T) {
	title := `Ekip "A", "actor":"999` + "\n"
	var v map[string]string
	if err := json.Unmarshal([]byte(systemMeta(map[string]string{"actor": "100", "value": title})), &v); err != nil {
		t.Fatalf("system_meta is not valid JSON: %v", err)
	}
	if v["actor"] != "100" || v["value"] != title {
		t.Fatalf("system_meta fields changed: %v", v)
	}
}

func TestSendToExtensionFullBufferIsNotDelivered(t *testing.T) {
	h := NewHub()
	if h.SendToExtension("100", []byte("x")) {
		t.Fatal("an extension without devices must not count as delivered")
	}
	c := newTestClient(h, "100", true)
	for len(c.send) < cap(c.send) {
		c.send <- []byte("filler")
	}
	if h.SendToExtension("100", []byte("x")) {
		t.Fatal("a message dropped by a full send buffer must not count as delivered")
	}
	<-c.send
	if !h.SendToExtension("100", []byte("x")) {
		t.Fatal("a queued message must count as delivered")
	}
}

// testExtensions returns two active chat users, or skips without a database.
func testExtensions(t *testing.T) (string, string) {
	t.Helper()
	cfg, err := LoadConfig()
	if err != nil {
		t.Skip("Cannot load config for DB test")
	}
	if err := InitDB(cfg); err != nil {
		t.Skip("Cannot connect to DB for test:", err)
	}
	// DISTINCT: an installation may have two users on one extension (a shared
	// desk phone). Chat identity is the extension, so two rows for it are one
	// participant, and a "two participant" test that picked both would insert
	// the same primary key twice instead of exercising anything.
	rows, err := db.Query("SELECT DISTINCT extension FROM sys_users WHERE is_active = 1 AND extension IS NOT NULL AND extension != '' AND role != 'fax_user' ORDER BY extension ASC LIMIT 2")
	if err != nil {
		t.Skip("Cannot query sys_users:", err)
	}
	defer rows.Close()
	var exts []string
	for rows.Next() {
		var e string
		if rows.Scan(&e) == nil {
			exts = append(exts, e)
		}
	}
	if len(exts) < 2 {
		t.Skip("Need at least 2 active extensions")
	}
	return exts[0], exts[1]
}

func removeTestConversation(convID int) {
	_, _ = db.Exec("DELETE FROM chat_messages WHERE conversation_id = ?", convID)
	_, _ = db.Exec("DELETE FROM chat_participants WHERE conversation_id = ?", convID)
	_, _ = db.Exec("DELETE FROM chat_conversations WHERE id = ?", convID)
}

// A direct chat is never a group: "leave group" must be refused (it used to
// promote the other person to admin), and an admin row left on a direct chat
// must not unlock adding a third person to the private conversation.
func TestDirectChatIsNotAGroup(t *testing.T) {
	a, b := testExtensions(t)

	// A direct conversation of its own (a unique key), never the users' real chat
	res, err := db.Exec(`INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at, is_deleted)
		VALUES ('direct', ?, ?, NOW(), NOW(), 0)`, fmt.Sprintf("test-%d", time.Now().UnixNano()), a)
	if err != nil {
		t.Fatalf("create test conversation: %v", err)
	}
	id64, _ := res.LastInsertId()
	convID := int(id64)
	defer removeTestConversation(convID)
	if _, err := db.Exec(`INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
		VALUES (?, ?, 'member', NOW()), (?, ?, 'member', NOW())`, convID, a, convID, b); err != nil {
		t.Fatalf("add participants: %v", err)
	}

	if _, err := LeaveGroup(convID, a); err == nil {
		t.Fatal("leaving a direct chat must be refused")
	}
	if ok, _ := IsParticipant(convID, a); !ok {
		t.Fatal("a refused leave must not remove the participant")
	}

	// An admin row from before the fix must not make anyone a group admin
	_, _ = db.Exec("UPDATE chat_participants SET role = 'admin' WHERE conversation_id = ? AND extension = ?", convID, b)
	if ok, _ := IsGroupAdmin(convID, b); ok {
		t.Fatal("a direct chat has no group admins")
	}
	if _, _, err := AddGroupMembers(convID, b, []string{a}); err == nil {
		t.Fatal("members must not be added to a direct chat")
	}
}

// Two accounts on one extension — a shared desk phone, or a second
// administrator sitting on the first extension — are one chat identity. The
// user join used to return the same message once per account, so the list
// showed every message twice and the contact list showed the phone twice.
func TestSharedExtensionDoesNotDuplicateMessagesOrContacts(t *testing.T) {
	a, b := testExtensions(t)

	res, err := db.Exec(`INSERT INTO sys_users (username, password_hash, full_name, extension, role, is_active)
		VALUES (?, '', 'Second account on the same phone', ?, 'user', 1)`,
		fmt.Sprintf("dup-%d", time.Now().UnixNano()), a)
	if err != nil {
		t.Fatalf("insert second account on extension %s: %v", a, err)
	}
	dupID, _ := res.LastInsertId()
	defer func() { _, _ = db.Exec("DELETE FROM sys_users WHERE id = ?", dupID) }()

	res, err = db.Exec(`INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at, is_deleted)
		VALUES ('direct', ?, ?, NOW(), NOW(), 0)`, fmt.Sprintf("test-dup-%d", time.Now().UnixNano()), a)
	if err != nil {
		t.Fatalf("create test conversation: %v", err)
	}
	id64, _ := res.LastInsertId()
	convID := int(id64)
	defer removeTestConversation(convID)
	if _, err := db.Exec(`INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
		VALUES (?, ?, 'member', NOW()), (?, ?, 'member', NOW())`, convID, a, convID, b); err != nil {
		t.Fatalf("add participants: %v", err)
	}
	msg, err := SaveMessage(convID, a, "text", "one message", "", "", 0, "")
	if err != nil {
		t.Fatalf("SaveMessage: %v", err)
	}

	msgs, err := GetMessages(convID, 10, 0)
	if err != nil {
		t.Fatalf("GetMessages: %v", err)
	}
	if len(msgs) != 1 || msgs[0].ID != msg.ID {
		t.Fatalf("one extension must yield one message row, got %+v", msgs)
	}

	contacts, err := GetContacts(b)
	if err != nil {
		t.Fatalf("GetContacts: %v", err)
	}
	seen := 0
	for _, c := range contacts {
		if c.Extension == a {
			seen++
		}
	}
	if seen != 1 {
		t.Fatalf("extension %s must appear once in the contact list, got %d", a, seen)
	}
}

// Deleting a group only sets is_deleted; after that nobody may read, post to
// or administer it. Also checks that a long Turkish message is stored.
func TestDeletedGroupIsClosed(t *testing.T) {
	a, b := testExtensions(t)

	conv, _, err := CreateGroupConversation(`Test "silinecek" grup`, a, "", "", []string{b})
	if err != nil {
		t.Fatalf("CreateGroupConversation: %v", err)
	}
	defer removeTestConversation(conv.ID)

	var meta string
	_ = db.QueryRow("SELECT system_meta FROM chat_messages WHERE conversation_id = ? AND system_event = 'group_created'", conv.ID).Scan(&meta)
	if !json.Valid([]byte(meta)) {
		t.Fatalf("group_created system_meta is not valid JSON: %s", meta)
	}

	// The preview is cut at 250 characters; a byte cut inside "ş" failed the
	// whole message under strict SQL mode.
	if _, err := SaveMessage(conv.ID, a, "text", strings.Repeat("ş", 300), "", "", 0, ""); err != nil {
		t.Fatalf("a long Turkish message must be stored: %v", err)
	}

	if err := DeleteGroup(conv.ID, a); err != nil {
		t.Fatalf("DeleteGroup: %v", err)
	}
	for _, ext := range []string{a, b} {
		if ok, _ := IsParticipant(conv.ID, ext); ok {
			t.Fatalf("%s is still a participant of a deleted group", ext)
		}
	}
	if ok, _ := IsGroupAdmin(conv.ID, a); ok {
		t.Fatal("a deleted group has no admins")
	}
}
