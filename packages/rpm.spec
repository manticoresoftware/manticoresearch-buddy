Summary: {{ DESC }}
Name: {{ NAME }}
Version: {{ VERSION }}
Release: 1%{?dist}
Group: Applications
License: GPLv2
Packager: {{ MAINTAINER }}
Vendor: {{ MAINTAINER }}
Requires: {{ LIBCURL_NAME }} >= {{ LIBCURL_VERSION }}

Source: tmp.tar.gz
BuildRoot: %{_tmppath}/%{name}-%{version}-buildroot
BuildArch: noarch

%description
{{ DESC }}

%prep
rm -rf %{buildroot}

%setup -n %{name}

%build

%install
mkdir -p %{buildroot}/usr/share/manticore/modules
cp -rp usr/share/manticore/modules/{{ NAME }} %{buildroot}/usr/share/manticore/modules/{{ NAME }}

%clean
rm -rf %{buildroot}

%post

%postun

%files
%defattr(-, root, root)
%dir /usr/share/manticore/modules/{{ NAME }}
%dir /usr/share/manticore/modules/{{ NAME }}/bin
/usr/share/manticore/modules/{{ NAME }}/src/*
/usr/share/manticore/modules/{{ NAME }}/vendor/*
/usr/share/manticore/modules/{{ NAME }}/APP_VERSION
/usr/share/manticore/modules/{{ NAME }}/composer.json
/usr/share/manticore/modules/{{ NAME }}/composer.lock
%attr(1755, root, root) /usr/share/manticore/modules/{{ NAME }}/bin/{{ NAME }}

%changelog
