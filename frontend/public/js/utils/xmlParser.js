var XMLParser = {
    parseMessages: function(xmlString) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(xmlString, 'text/xml');
        var messages = [];
        var msgNodes = doc.getElementsByTagName('message');
        for (var i = 0; i < msgNodes.length; i++) {
            var msg = {};
            var children = msgNodes[i].childNodes;
            for (var j = 0; j < children.length; j++) {
                var child = children[j];
                if (child.nodeType === 1) msg[child.nodeName] = child.textContent;
            }
            if (Object.keys(msg).length > 0) messages.push(msg);
        }
        return messages;
    },
    buildMessagesXML: function(messages) {
        var doc = document.implementation.createDocument(null, 'emergency_messaging_export', null);
        var root = doc.documentElement;
        var generatedAt = doc.createElement('generated_at');
        generatedAt.textContent = new Date().toISOString();
        root.appendChild(generatedAt);
        var msgCount = doc.createElement('message_count');
        msgCount.textContent = messages.length;
        root.appendChild(msgCount);
        var msgsContainer = doc.createElement('messages');
        for (var i = 0; i < messages.length; i++) {
            var msgEl = doc.createElement('message');
            for (var key in messages[i]) {
                if (messages[i].hasOwnProperty(key)) {
                    var el = doc.createElement(key);
                    el.textContent = String(messages[i][key]);
                    msgEl.appendChild(el);
                }
            }
            msgsContainer.appendChild(msgEl);
        }
        root.appendChild(msgsContainer);
        var serializer = new XMLSerializer();
        return serializer.serializeToString(doc);
    },
    validateXML: function(xmlString) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(xmlString, 'text/xml');
        var parseError = doc.getElementsByTagName('parsererror');
        return parseError.length === 0;
    },
};
